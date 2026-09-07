<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'company_id' => $this->company_id,
            // research.md R12 — business_type dibaca live via relasi
            // company.businessType (BUKAN snapshot seperti license/subtotal
            // di bawah): business type mendeskripsikan Company itu sendiri,
            // bukan fakta pembayaran yang harus dibekukan sejak invoice
            // dibuat, jadi selalu mencerminkan kondisi Company saat ini.
            'company' => $this->whenLoaded('company', fn () => [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'business_type' => $this->company->relationLoaded('businessType') && $this->company->businessType
                    ? [
                        'id' => $this->company->businessType->id,
                        'name' => $this->company->businessType->name,
                    ]
                    : null,
            ]),
            'license_id' => $this->license_id,
            'license' => $this->whenLoaded('license', fn () => [
                'id' => $this->license->id,
                'name' => $this->license->name,
                'payment_type' => $this->license->payment_type,
            ]),
            'subtotal' => number_format((float) $this->subtotal, 2, '.', ''),
            'discount' => number_format((float) $this->discount, 2, '.', ''),
            'grand_total' => number_format((float) $this->grand_total, 2, '.', ''),
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status,
            'paid_at' => $this->paid_at?->toDateString(),
            'payment_information' => $this->payment_information,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
