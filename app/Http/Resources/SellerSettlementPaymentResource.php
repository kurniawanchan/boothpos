<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SellerSettlementPayment */
class SellerSettlementPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'paid_at' => $this->paid_at?->toDateString(),
            'method' => $this->method,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'has_proof' => $this->proof_path !== null,
            'recorded_by_name' => $this->whenLoaded('recorder', fn () => $this->recorder?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
