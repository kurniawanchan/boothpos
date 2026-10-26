<?php

namespace App\Http\Resources;

use App\Models\SellerSettlement;
use App\Services\SettlementReconciliation;
use App\Support\ReportSplit;
use Illuminate\Http\Request;

/**
 * 076 — detail settlement. Resource TIDAK memanggil service: isyarat
 * "sumber berubah" dihitung pemanggil (controller) dan diberikan lewat
 * konstruktor, supaya payload ini tetap murni bentuk data.
 *
 * `can.*` dihitung di server dari status & pembayaran; SPA tidak pernah
 * menurunkan boleh/tidaknya sendiri. Relasi yang dibaca di sini
 * (sources, adjustments, acknowledgements, payments) dimuat oleh
 * SellerSettlementService::loadDetail().
 *
 * @mixin SellerSettlement
 */
class SellerSettlementDetailResource extends SellerSettlementResource
{
    public function __construct($resource, private readonly ?array $sourceChanged = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        /** @var SellerSettlement $s */
        $s = $this->resource;

        $history = $s->acknowledgements->whereNotNull('superseded_at')->values();
        $cogsCents = ReportSplit::cents($s->memo_cogs_pos) + ReportSplit::cents($s->memo_cogs_preorder);
        $hasPayments = $s->payments->isNotEmpty();

        return parent::toArray($request) + [
            'sources' => SellerSettlementSourceResource::collection($s->sources),
            'adjustments' => SellerSettlementAdjustmentResource::collection($s->adjustments),
            'acknowledgement' => $s->currentAcknowledgement
                ? new SellerSettlementAcknowledgementResource($s->currentAcknowledgement)
                : null,
            'acknowledgement_history' => SellerSettlementAcknowledgementResource::collection($history),
            'payments' => SellerSettlementPaymentResource::collection($s->payments),
            'reconciliation' => SettlementReconciliation::for($s),
            'memo' => [
                'preorder_uncollected' => $s->memo_preorder_uncollected,
                'cost_of_goods' => self::money($cogsCents),
                'gross_profit' => self::money($s->revenueCents() - $cogsCents),
            ],
            'source_changed' => $this->sourceChanged,
            'can' => [
                'finalize' => $s->isDraft(),
                'reopen' => $s->isFinalized() && ! $hasPayments,
                'delete' => $s->isDraft() && $s->number === null,
                'acknowledge' => $s->isFinalized(),
                'add_payment' => $s->isFinalized() && $s->netCents() !== 0 && $s->balanceCents() > 0,
                'adjust' => true,
            ],
        ];
    }
}
