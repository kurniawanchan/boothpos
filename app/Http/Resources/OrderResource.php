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
            'created_at' => $this->created_at,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id, 'variant_id' => $i->variant_id, 'artist_id' => $i->artist_id,
                'sku_snapshot' => $i->sku_snapshot, 'name_snapshot' => $i->name_snapshot,
                'qty' => $i->qty, 'sell_price' => number_format((float) $i->sell_price, 2, '.', ''),
                'line_total' => number_format((float) $i->line_total, 2, '.', ''),
            ])),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($p) => [
                'id' => $p->id, 'method' => $p->method, 'amount' => number_format((float) $p->amount, 2, '.', ''),
                'verification' => $p->verification,
            ])),
        ];
    }
}
