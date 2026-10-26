<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pengakuan seller. Berkas tanda tangan TIDAK pernah dikirim di sini —
 * hanya `has_signature`; gambarnya diambil lewat endpoint yang memeriksa
 * hak akses.
 *
 * @mixin \App\Models\SellerSettlementAcknowledgement
 */
class SellerSettlementAcknowledgementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'method' => $this->method,
            'signer_name' => $this->signer_name,
            'note' => $this->note,
            'has_signature' => $this->signature_path !== null,
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
            'recorded_by_name' => $this->whenLoaded('recorder', fn () => $this->recorder?->name),
            'superseded_at' => $this->superseded_at?->toIso8601String(),
            'superseded_reason' => $this->superseded_reason,
        ];
    }
}
