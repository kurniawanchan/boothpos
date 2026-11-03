<?php

namespace App\Http\Resources;

use App\Models\Outlet;
use App\Models\SelfServiceOrder;
use App\Support\PosSettings;
use App\Support\StoreBranding;
use App\Support\StoreIdentity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 090 / US3 — struk untuk PELANGGAN (halaman publik, dibuka lewat token struk acak). Bentuk DAFTAR-PUTIH sendiri,
 * terpisah dari resource staf: hanya yang pantas dilihat pemilik struk. Tidak memuat id internal, HPP, telepon/surel
 * pelanggan, bukti bayar, atau pengguna staf. Nama pelanggan hanya bila ia mengisinya.
 *
 * Data berasal dari POTRET saat kirim (`lines`, total, pajak) — bukan dihitung ulang dari data master — jadi struk
 * tidak berubah walau harga menu kemudian diubah. Hanya `status`, nomor order POS, dan alasan tolak yang bergerak.
 *
 * @mixin SelfServiceOrder
 */
class SelfServiceReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $money = fn ($v) => number_format((float) $v, 2, '.', '');
        $outlet = Outlet::query()->find($this->outlet_id);
        // Identitas toko per OUTLET transaksi (079); hanya blok yang boleh publik — tanpa surel/catatan internal.
        $store = StoreIdentity::forReceipt($outlet);

        return [
            'reference_no' => $this->reference_no,
            // Nomor POS baru ada setelah dikirim ke dapur (research D18); null sebelum itu.
            'pos_order_number' => $this->status === SelfServiceOrder::DISPATCHED ? $this->order?->order_number : null,
            'status' => $this->status,
            'reject_reason' => $this->status === SelfServiceOrder::REJECTED ? $this->reject_reason : null,
            'store' => [
                'name' => $store['name'] ?? $outlet?->name,
                'address' => $store['address'],
                'phone' => $store['phone'],
                'logo_url' => StoreBranding::logoUrl(),
            ],
            'table_name' => $this->table_name_snapshot,
            'service_type' => $this->service_type,
            'lines' => collect($this->lines)->map(fn ($l) => [
                'name' => $l['name'],
                'qty' => (int) $l['qty'],
                'unit_price' => $l['unit_price'],
                'line_total' => $l['line_total'],
                'note' => $l['note'] ?? null,
                'modifiers' => collect($l['modifiers'] ?? [])->map(fn ($m) => ['group' => $m['group'] ?? null, 'option' => $m['option'] ?? null, 'qty' => (int) ($m['qty'] ?? 1)])->all(),
            ])->all(),
            'subtotal' => $money($this->subtotal),
            // Label pajak hanya bila memang ada pajak pada pesanan ini (baris pajak tampil bila nilainya > 0).
            'tax_label' => (float) $this->tax_amount > 0 ? PosSettings::taxLabel() : null,
            'tax_amount' => $money($this->tax_amount),
            'total_amount' => $money($this->total_amount),
            'payment' => [
                'method' => 'QRIS',
                'status' => match ($this->status) {
                    SelfServiceOrder::AWAITING => 'waiting_verification',
                    SelfServiceOrder::REJECTED => 'rejected',
                    default => 'verified',
                },
            ],
            // Hanya nama (sapaan di struk); telepon/surel tidak pernah keluar dari rute publik.
            'customer_name' => $this->customer_name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
