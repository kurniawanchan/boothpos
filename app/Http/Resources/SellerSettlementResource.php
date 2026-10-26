<?php

namespace App\Http\Resources;

use App\Support\ReportSplit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 076 — ringkasan settlement (baris daftar + kepala detail). Event/seller
 * dibaca dari SNAPSHOT di barisnya (nama saat dibuat), bukan dari tabel
 * induk, supaya tetap benar setelah rename/soft delete. Nilai turunan
 * (arah, saldo, status) dihitung dari kolom, tidak pernah disimpan.
 *
 * `acknowledged` membaca relasi currentAcknowledgement; daftar WAJIB
 * meng-eager-load-nya (perangkap relationLoaded di CLAUDE.md).
 *
 * @mixin \App\Models\SellerSettlement
 */
class SellerSettlementResource extends JsonResource
{
    protected function acknowledged(): bool
    {
        return $this->relationLoaded('currentAcknowledgement')
            ? $this->currentAcknowledgement !== null
            : $this->currentAcknowledgement()->exists();
    }

    public function toArray(Request $request): array
    {
        $acknowledged = $this->acknowledged();

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'display_status' => $this->displayStatus($acknowledged),
            'event' => ['id' => $this->event_id, 'name' => $this->event_name],
            'seller' => ['id' => $this->artist_id, 'name' => $this->artist_name, 'code' => $this->artist_code],
            'pos_units' => (int) $this->pos_units,
            'pos_revenue' => $this->pos_revenue,
            'preorder_units' => (int) $this->preorder_units,
            'preorder_revenue' => $this->preorder_revenue,
            'revenue_total' => $this->revenue_total,
            'adjustments_total' => $this->adjustments_total,
            'net_amount' => $this->net_amount,
            'direction' => $this->direction(),
            'paid_amount' => $this->paid_amount,
            'balance' => $this->balance(),
            'payment_status' => $this->paymentStatus(),
            'acknowledged' => $acknowledged,
            'finalized_at' => $this->finalized_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** Dipakai juga oleh DetailResource untuk memo biaya/laba. */
    protected static function money(int $cents): string
    {
        return ReportSplit::money($cents);
    }
}
