<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 028 — bentuk tab (kontrak: contracts/api.md). Aman untuk kasir: TIDAK ada
 * field biaya (cost_price/total_cost). Relasi yang dibutuhkan (`items`,
 * `payments`, `table`) dimuat oleh TabService::loaded() — bila ada yang tidak
 * dimuat, field itu hilang diam-diam, jadi setiap endpoint harus lewat sana.
 *
 * @mixin \App\Models\Order
 */
class TabResource extends JsonResource
{
    private static function money(mixed $v): string
    {
        return number_format((float) $v, 2, '.', '');
    }

    public function toArray(Request $request): array
    {
        $total = (float) $this->total_amount;
        $paid = (float) $this->paid_amount;

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'service_type' => $this->service_type,
            'table' => $this->relationLoaded('table') && $this->table
                ? ['id' => $this->table->id, 'name' => $this->table->name, 'area' => $this->table->area]
                : null,
            'tab_label' => $this->tab_label,
            // 044 — siapa yang membuka tab (detail baca-saja di papan Meja). Hilang diam-diam bila `cashier` tak dimuat.
            'opened_by' => $this->relationLoaded('cashier') ? $this->cashier?->name : null,
            'session_id' => $this->session_id,
            'customer_id' => $this->customer_id,
            'subtotal' => self::money($this->subtotal),
            'discount_amount' => self::money($this->discount_amount),
            // 048 — pajak eksklusif (snapshot saat transaksi) + penomoran + pra-bill. Field SELALU ada (null/"0.00" bila belum).
            'tax_amount' => self::money($this->tax_amount),
            'tax_rate' => self::money($this->tax_rate_snapshot),
            'queue_no' => $this->queue_no,
            'receipt_no' => $this->receipt_no,
            'guest_count' => $this->guest_count,
            'prebill_at' => $this->prebill_at?->toIso8601String(),
            'device' => $this->relationLoaded('device') && $this->device ? ['id' => $this->device->id, 'code' => $this->device->code] : null,
            'total_amount' => self::money($total),
            'paid_amount' => self::money($paid),
            'change_amount' => self::money($this->change_amount),
            'balance' => self::money(max(0, $total - $paid)),
            'created_at' => $this->created_at?->toIso8601String(),
            'lines' => $this->whenLoaded('items', fn () => $this->items->sortBy('id')->values()->map(fn ($i) => [
                'id' => $i->id,
                'variant_id' => $i->variant_id,
                'name' => $i->name_snapshot,
                'qty' => $i->qty,
                'unit_price' => self::money($i->sell_price),
                'line_total' => self::money($i->line_total),
                'modifiers' => \App\Support\ModifierSnapshot::forDisplay($i->modifiers),
                'note' => $i->note,
                'sent' => $i->sent_at !== null,
                // Status persiapan dari item tiket dapur; null selama baris belum dikirim.
                'prep_status' => $i->relationLoaded('ticketItem') ? $i->ticketItem?->status : null,
                // Dipakai pelayan untuk menandai "sudah diantar" (PATCH /ticket-items/{id}/status).
                'ticket_item_id' => $i->relationLoaded('ticketItem') ? $i->ticketItem?->id : null,
                'station' => $i->relationLoaded('ticketItem') && $i->ticketItem?->ticket?->station
                    ? ['id' => $i->ticketItem->ticket->station->id, 'name' => $i->ticketItem->ticket->station->name]
                    : null,
            ])),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($p) => [
                'id' => $p->id,
                'method' => $p->method,
                'amount' => self::money($p->amount),
                'verification' => $p->verification,
                'paid_at' => $p->paid_at?->toIso8601String(),
            ])),
        ];
    }
}
