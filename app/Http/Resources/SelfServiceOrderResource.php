<?php

namespace App\Http\Resources;

use App\Models\SelfServiceOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 090 — pesanan mandiri untuk STAF (tab Self-Service). Daftar ramping; `detail` menambah baris dan rincian pembayaran.
 * Bukan resource publik: struk pelanggan punya bentuk daftar-putih sendiri (SelfServiceReceiptResource).
 *
 * @mixin SelfServiceOrder
 */
class SelfServiceOrderResource extends JsonResource
{
    public function __construct($resource, private readonly bool $detail = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $money = fn ($v) => number_format((float) $v, 2, '.', '');

        return [
            'id' => $this->id,
            'reference_no' => $this->reference_no,
            'status' => $this->status,
            'business_date' => $this->business_date?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'table' => ['id' => $this->table_id, 'name' => $this->table_name_snapshot],
            'service_type' => $this->service_type,
            'item_count' => collect($this->lines)->sum('qty'),
            'subtotal' => $money($this->subtotal),
            'tax_amount' => $money($this->tax_amount),
            'total_amount' => $money($this->total_amount),
            // Data pribadi pelanggan: daftar hanya menampilkan telepon tersamar; detail (staf berwenang) penuh. Tamu = null.
            'customer' => $this->customer_name === null && $this->customer_phone === null ? null : [
                'name' => $this->customer_name,
                'phone' => $this->detail ? $this->customer_phone : \App\Support\PhoneNormalizer::masked($this->customer_phone),
                'email' => $this->when($this->detail, fn () => $this->customer_email),
            ],
            'payment' => [
                'method' => 'qris',
                'channel' => $this->relationLoaded('channel') ? $this->channel?->provider : null,
                'amount' => $money($this->total_amount),
                // waiting_verification | verified | rejected — dari status entitas (belum ada baris `payments` sebelum dispatch).
                'status' => match ($this->status) {
                    SelfServiceOrder::AWAITING => 'waiting_verification',
                    SelfServiceOrder::REJECTED => 'rejected',
                    default => 'verified',
                },
                'reference' => $this->verify_reference,
                'verified_at' => $this->verified_at?->toIso8601String(),
                'verified_by' => $this->relationLoaded('verifier') ? $this->verifier?->name : null,
                'proof_id' => $this->proof_id,
            ],
            'rejection' => $this->status === SelfServiceOrder::REJECTED ? [
                'reason' => $this->reject_reason,
                'at' => $this->rejected_at?->toIso8601String(),
                'by' => $this->relationLoaded('rejecter') ? $this->rejecter?->name : null,
            ] : null,
            'order' => $this->order_id !== null ? [
                'id' => $this->order_id,
                'order_number' => $this->relationLoaded('order') ? $this->order?->order_number : null,
                'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            ] : null,
            'lines' => $this->when($this->detail, fn () => collect($this->lines)->map(fn ($l) => [
                'name' => $l['name'], 'qty' => (int) $l['qty'], 'unit_price' => $l['unit_price'], 'line_total' => $l['line_total'],
                'note' => $l['note'] ?? null,
                'modifiers' => collect($l['modifiers'] ?? [])->map(fn ($m) => ['group' => $m['group'] ?? null, 'option' => $m['option'] ?? null, 'qty' => (int) ($m['qty'] ?? 1)])->all(),
            ])->all()),
        ];
    }
}
