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
            // 019-billing-system (third expansion, research.md R14) —
            // sinyal untuk frontend kapan tombol "Activate" boleh
            // ditampilkan: belum aktif DAN sudah punya minimal satu
            // Invoice 'paid'. paid_invoices_count di-set via withCount()/
            // loadCount() di CompanyController, bukan query N+1 di sini.
            'can_activate' => $this->status !== 'active' && ($this->paid_invoices_count ?? 0) > 0,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'business_type' => new BusinessTypeResource($this->whenLoaded('businessType')),
            'license' => new LicenseResource($this->whenLoaded('license')),
            'owner_username' => $this->whenLoaded('owner', fn () => $this->owner->username),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
