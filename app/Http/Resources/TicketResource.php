<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 028 US4 — tiket dapur. `waiting_seconds` sengaja satu-satunya field yang berubah menurut
 * jam; umpan layar membuangnya saat menghitung token versi (KitchenController).
 *
 * @mixin \App\Models\KitchenTicket
 */
class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $order = $this->order;

        return [
            'id' => $this->id,
            'round_no' => $this->round_no,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'station' => $this->station ? ['id' => $this->station->id, 'name' => $this->station->name] : null,
            'order' => $order ? ['id' => $order->id, 'order_number' => $order->order_number] : null,
            'table' => $order?->table ? ['id' => $order->table->id, 'name' => $order->table->name] : null,
            'tab_label' => $order?->tab_label,
            // 078 — catatan kasir untuk seluruh order, dibaca LANGSUNG dari order (tanpa salinan di tiket): cetak ulang menampilkan catatan
            // terbaru dan dapur melihatnya sebelum item. Catatan per item tetap di `items[].note`.
            'order_note' => $order?->notes,
            'waiting_seconds' => max(0, (int) $this->sent_at?->diffInSeconds(now(), false)),
            'items' => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->name_snapshot,
                'qty' => $i->qty,
                'modifiers' => \App\Support\ModifierSnapshot::forDisplay($i->modifiers),
                'note' => $i->note,
                'status' => $i->status,
                'preparing_at' => $i->preparing_at?->toIso8601String(),
                'ready_at' => $i->ready_at?->toIso8601String(),
                'served_at' => $i->served_at?->toIso8601String(),
                'cancelled_at' => $i->cancelled_at?->toIso8601String(),
                'cancel_reason' => $i->cancel_reason,
            ])->values(),
        ];
    }
}
