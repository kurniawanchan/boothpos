<?php

namespace App\Support;

use App\Exceptions\SettlementRuleException;
use App\Models\SellerSettlement;
use App\Models\SellerSettlementPayment;
use App\Services\ImageUploadService;
use App\Services\SettlementReconciliation;

/**
 * 076-settlement-tab-session-branding — bahan dokumen cetak (bukti settlement
 * dan bukti pembayaran). Dirender di browser seperti dokumen lain di aplikasi
 * ini; di sini hanya data.
 *
 * Aturan isi:
 *  - identitas toko memakai perakitan yang SAMA dengan invoice/struk
 *    (BuildsInvoiceDocument::buildStoreIdentity), bukan salinan baru;
 *  - hanya NOMOR transaksi — tidak ada nama/telepon/email pelanggan (FR-023);
 *  - biaya pokok & laba kotor (margin toko) TIDAK ikut kecuali diminta
 *    eksplisit (`includeCost`), karena dokumen ini diserahkan ke seller;
 *  - hanya settlement yang sudah final yang punya dokumen.
 */
class SettlementDocument
{
    use BuildsInvoiceDocument;

    public function __construct(private readonly ImageUploadService $images) {}

    /** @return array<string, mixed> */
    public function forSettlement(SellerSettlement $settlement, bool $includeCost): array
    {
        $this->assertFinalized($settlement);
        $settlement->loadMissing(['sources', 'adjustments', 'payments.recorder', 'currentAcknowledgement.recorder']);

        $ack = $settlement->currentAcknowledgement;

        $document = [
            'store_identity' => $this->buildStoreIdentity($this->images),
            'app_name' => AppName::current(),
            'settlement' => $this->header($settlement),
            'lines' => [
                'pos_units' => (int) $settlement->pos_units,
                'pos_revenue' => $settlement->pos_revenue,
                'preorder_units' => (int) $settlement->preorder_units,
                'preorder_revenue' => $settlement->preorder_revenue,
                'revenue_total' => $settlement->revenue_total,
                'adjustments' => $settlement->adjustments->map(fn ($a) => [
                    'kind' => $a->kind, 'label' => $a->label, 'reason' => $a->reason, 'basis' => $a->basis,
                    'amount' => $a->amount, 'post_finalization' => (bool) $a->post_finalization,
                ])->values()->all(),
                'adjustments_total' => $settlement->adjustments_total,
                'net_amount' => $settlement->net_amount,
                'paid_amount' => $settlement->paid_amount,
                'balance' => $settlement->balance(),
            ],
            // Hanya nomor, tanggal, unit, dan nilai — tanpa biaya pokok per baris.
            'sources' => $settlement->sources->map(fn ($s) => [
                'source_type' => $s->source_type,
                'source_number' => $s->source_number,
                'source_date' => $s->source_date?->toIso8601String(),
                'units' => (int) $s->units,
                'gross_amount' => $s->gross_amount,
                'amount' => $s->amount,
            ])->values()->all(),
            'reconciliation' => SettlementReconciliation::for($settlement),
            'payments' => $settlement->payments->map(fn ($p) => [
                'amount' => $p->amount, 'paid_at' => $p->paid_at?->toDateString(),
                'method' => $p->method, 'reference' => $p->reference,
            ])->values()->all(),
            'acknowledgement' => $ack ? [
                'id' => $ack->id,
                'method' => $ack->method,
                'signer_name' => $ack->signer_name,
                'note' => $ack->note,
                'has_signature' => $ack->signature_path !== null,
                'acknowledged_at' => $ack->acknowledged_at?->toIso8601String(),
            ] : null,
            'memo' => ['preorder_uncollected' => $settlement->memo_preorder_uncollected],
        ];

        if ($includeCost) {
            $cogs = ReportSplit::cents($settlement->memo_cogs_pos) + ReportSplit::cents($settlement->memo_cogs_preorder);
            $document['memo']['cost_of_goods'] = ReportSplit::money($cogs);
            $document['memo']['gross_profit'] = ReportSplit::money($settlement->revenueCents() - $cogs);
        }

        return $document;
    }

    /** @return array<string, mixed> */
    public function forPayment(SellerSettlement $settlement, SellerSettlementPayment $payment): array
    {
        $this->assertFinalized($settlement);
        $settlement->loadMissing('payments');
        $payment->loadMissing('recorder');

        // Urutan kronologis yang sama dengan relasi payments(): tanggal bayar lalu id.
        $paidThrough = $settlement->payments
            ->filter(fn ($p) => ($p->paid_at->toDateString() <=> $payment->paid_at->toDateString()) < 0
                || ($p->paid_at->toDateString() === $payment->paid_at->toDateString() && $p->id <= $payment->id))
            ->sum(fn ($p) => ReportSplit::cents($p->amount));
        $owed = abs($settlement->netCents());

        return [
            'store_identity' => $this->buildStoreIdentity($this->images),
            'app_name' => AppName::current(),
            'settlement' => $this->header($settlement) + ['net_amount' => $settlement->net_amount],
            'payment' => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'paid_at' => $payment->paid_at?->toDateString(),
                'method' => $payment->method,
                'reference' => $payment->reference,
                'notes' => $payment->notes,
                'has_proof' => $payment->proof_path !== null,
                'recorded_by_name' => $payment->recorder?->name,
            ],
            'total_paid_after' => ReportSplit::money($paidThrough),
            'balance_after' => ReportSplit::money(max(0, $owed - $paidThrough)),
        ];
    }

    /** @return array<string, mixed> */
    private function header(SellerSettlement $settlement): array
    {
        return [
            'number' => $settlement->number,
            'event' => ['name' => $settlement->event_name],
            'seller' => ['name' => $settlement->artist_name, 'code' => $settlement->artist_code],
            'direction' => $settlement->direction(),
            'finalized_at' => $settlement->finalized_at?->toIso8601String(),
        ];
    }

    private function assertFinalized(SellerSettlement $settlement): void
    {
        if (! $settlement->isFinalized()) {
            throw SettlementRuleException::make('not_finalized');
        }
    }
}
