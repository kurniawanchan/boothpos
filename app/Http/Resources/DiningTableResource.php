<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DiningTable */
class DiningTableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'area' => $this->area ?? '',
            'seats' => $this->seats,
            'display_order' => $this->display_order,
            'is_active' => $this->is_active,
            // Diisi withExists('openTab as has_open_tab') pada daftar; false bila tidak dimuat.
            'has_open_tab' => (bool) ($this->has_open_tab ?? false),
        ];
    }
}
