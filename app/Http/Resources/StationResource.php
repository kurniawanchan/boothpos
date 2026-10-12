<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Station */
class StationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // 045 Stage 2 — outlet pemilik (tetap sejak dibuat).
            'outlet_id' => $this->outlet_id,
            'display_order' => $this->display_order,
            'is_active' => $this->is_active,
        ];
    }
}
