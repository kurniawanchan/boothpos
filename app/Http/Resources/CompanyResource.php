<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'contact_name' => $this->contact_name,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'status' => $this->status,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'business_type' => new BusinessTypeResource($this->whenLoaded('businessType')),
            'package' => new PackageResource($this->whenLoaded('package')),
            'owner_username' => $this->whenLoaded('owner', fn () => $this->owner->username),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
