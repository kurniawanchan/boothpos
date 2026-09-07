<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LicenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'license_tier' => $this->license_tier,
            // Uang selalu string terformat (konvensi kodebase ini) —
            // lihat CLAUDE.md "API conventions".
            'price' => number_format((float) $this->price, 2, '.', ''),
            'payment_type' => $this->payment_type,
            'is_active' => $this->is_active,
            'company_count' => $this->companies_count ?? null,
        ];
    }
}
