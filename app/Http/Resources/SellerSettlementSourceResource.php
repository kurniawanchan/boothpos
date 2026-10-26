<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Baris sumber beku. Hanya nomor transaksi — TIDAK ada nama/telepon/email
 * pelanggan (FR-023, minimisasi data).
 *
 * @mixin \App\Models\SellerSettlementSource
 */
class SellerSettlementSourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_type' => $this->source_type,
            'source_id' => (int) $this->source_id,
            'source_number' => $this->source_number,
            'source_date' => $this->source_date?->toIso8601String(),
            'units' => (int) $this->units,
            'gross_amount' => $this->gross_amount,
            'amount' => $this->amount,
            'uncollected_amount' => $this->uncollected_amount,
            'cost_amount' => $this->cost_amount,
        ];
    }
}
