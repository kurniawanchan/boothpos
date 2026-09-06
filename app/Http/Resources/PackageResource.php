<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'license_tier' => $this->license_tier,
            'is_active' => $this->is_active,
            'company_count' => $this->companies_count ?? null,
        ];
    }
}
