<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SellerSettlementAdjustment */
class SellerSettlementAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'label' => $this->label,
            'reason' => $this->reason,
            'basis' => $this->basis,
            'amount' => $this->amount,
            'post_finalization' => (bool) $this->post_finalization,
            // Hanya bila relasi dimuat (loadDetail memuatnya).
            'created_by_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
