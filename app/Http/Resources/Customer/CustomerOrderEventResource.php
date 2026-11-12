<?php

namespace App\Http\Resources\Customer;

use App\Models\CustomerOrderStatusEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer commerce (CONTRACT §8) — satu peristiwa linimasa pesanan untuk
 * pelanggan: status, jenis peristiwa, catatan polos, dan waktunya.
 *
 * `metadata`, `actor_type`, `actor_user_id`, dan nama staf adalah detail
 * audit: TIDAK PERNAH ikut di sini (resource staf menampilkannya sendiri).
 *
 * @mixin CustomerOrderStatusEvent
 */
class CustomerOrderEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status,
            'event_type' => $this->event_type,
            'note' => $this->note,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
