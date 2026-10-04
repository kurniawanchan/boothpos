<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'event_id' => $this->event_id,
            'session_id' => $this->session_id,
            'customer_id' => $this->customer_id,
            'subtotal' => number_format((float) $this->subtotal, 2, '.', ''),
            'discount_amount' => number_format((float) $this->discount_amount, 2, '.', ''),
            'total_amount' => number_format((float) $this->total_amount, 2, '.', ''),
            'paid_amount' => number_format((float) $this->paid_amount, 2, '.', ''),
            'change_amount' => number_format((float) $this->change_amount, 2, '.', ''),
            'status' => $this->status,
            'void_reason' => $this->void_reason,
            'notes' => $this->notes,
            'channel' => $this->channel,
            'created_at' => $this->created_at,
            // null (kunci tetap ada) untuk pembeli walk-in; hilang bila relasi tak dimuat.
            'customer' => $this->whenLoaded('customer', fn ($c) => ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'email' => $c->email]),
            'cashier' => $this->whenLoaded('cashier', fn ($u) => ['id' => $u->id, 'name' => $u->name]),
            'event' => $this->whenLoaded('event', fn ($e) => ['id' => $e->id, 'name' => $e->name]),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id, 'variant_id' => $i->variant_id, 'artist_id' => $i->artist_id,
                // product_id hanya terisi bila relasi variant dimuat (lihat
                // OrderController::show()) — dibutuhkan popup "Produk Terjual"
                // di Sales (009-ui-ux-refinements US2) untuk membuka
                // ProductDetailModal dari klik nama produk.
                'product_id' => $i->relationLoaded('variant') ? $i->variant?->product_id : null,
                'sku_snapshot' => $i->sku_snapshot, 'name_snapshot' => $i->name_snapshot,
                'qty' => $i->qty, 'sell_price' => number_format((float) $i->sell_price, 2, '.', ''),
                'line_total' => number_format((float) $i->line_total, 2, '.', ''),
                'discount_amount' => number_format((float) $i->discount_amount, 2, '.', ''),
                // Field tampilan — semuanya null-safe: varian/produk bisa saja sudah
                // dihapus/dipindah kategori sejak transaksi terjadi. cost_price
                // SENGAJA tidak disertakan (halaman ini terbuka untuk kasir).
                'artist_name' => $i->relationLoaded('artist') ? $i->artist?->name : null,
                'category_name' => $i->relationLoaded('variant') ? $i->variant?->product?->category?->name : null,
                'variant_name' => $i->relationLoaded('variant') ? $i->variant?->variant_name : null,
                'image_url' => $i->relationLoaded('variant') ? $i->variant?->image_url : null,
            ])),
            // 028-partial-split-payment — ringkasan turunan dari entri pembayaran.
            'payment_summary' => $this->whenLoaded('payments', fn () => \App\Support\PaymentSummary::for($this->resource)),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(function ($p) use ($request) {
                $user = $request->user();
                $row = [
                    'id' => $p->id, 'method' => $p->method, 'amount' => number_format((float) $p->amount, 2, '.', ''),
                    'verification' => $p->verification,
                    'paid_at' => $p->paid_at,
                    'reference' => $p->reference,
                    // 031 — catatan pembayaran; sebelumnya tersimpan tetapi tak pernah dikirim.
                    'notes' => $p->notes,
                    'recorded_by_name' => $p->relationLoaded('recorder') ? $p->recorder?->name : null,
                    'status' => $p->verification === 'rejected' ? 'rejected' : 'paid',
                    'session_id' => $p->session_id,
                    // Nama kanal (bank/e-wallet) bila ada; null untuk tunai.
                    'provider' => $p->relationLoaded('channel') ? $p->channel?->provider : null,
                    // 031 — siapa boleh mengubah konfirmasi dihitung SERVER (SPA tak pernah menebak
                    // dari peran); transaksi batal tak bisa diubah (FR-012).
                    'can_edit_confirmation' => $user !== null && $this->status !== 'voided' && $p->confirmationEditableBy($user),
                ];

                // Hanya bila bukti dimuat: tanpa `payments.proofs` kolom-kolom ini DIHILANGKAN,
                // bukan diisi "tak ada bukti" (jebakan relationLoaded yang sama seperti present()).
                if ($p->relationLoaded('proofs')) {
                    $current = $p->currentProof();
                    $row['proof_id'] = $current?->id;
                    $row['has_proof'] = $current !== null;
                    $row['can_view_proof'] = $user !== null && $current !== null && $p->proofViewableBy($user, $current);
                }

                return $row;
            })),
        ];
    }
}
