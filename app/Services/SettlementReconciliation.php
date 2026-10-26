<?php

namespace App\Services;

use App\Models\SellerSettlement;
use App\Support\ReportSplit;

/**
 * 076-settlement-tab-session-branding — bukti keseimbangan settlement.
 *
 * Dihitung TERPISAH dari total yang tersimpan: menjumlah ulang baris-baris
 * (sumber, penyesuaian, pembayaran) dalam sen integer lalu membandingkannya
 * dengan kolom total. Jadi ini bukan "membaca total sendiri" — kalau ada bug
 * atau data diubah di luar service, selisihnya muncul di sini dan finalisasi
 * ditolak (409 reconciliation_mismatch). Hasilnya juga ditampilkan ke
 * pengguna sebagai bukti, bukan hanya dipakai sebagai gerbang.
 */
final class SettlementReconciliation
{
    /**
     * @return array{
     *   sources_sum: string, adjustments_sum: string, expected_net: string, net_amount: string,
     *   difference: string, pos_check: string, preorder_check: string, payment_check: string,
     *   balanced: bool, failing: ?string
     * }
     */
    public static function for(SellerSettlement $settlement): array
    {
        $sources = $settlement->relationLoaded('sources') ? $settlement->sources : $settlement->sources()->get();
        $adjustments = $settlement->relationLoaded('adjustments') ? $settlement->adjustments : $settlement->adjustments()->get();
        $payments = $settlement->relationLoaded('payments') ? $settlement->payments : $settlement->payments()->get();

        $posRows = $sources->where('source_type', 'order')->sum(fn ($r) => ReportSplit::cents($r->amount));
        $preRows = $sources->where('source_type', 'preorder')->sum(fn ($r) => ReportSplit::cents($r->amount));
        $sourcesSum = $posRows + $preRows;
        $adjustmentsSum = $adjustments->sum(fn ($r) => ReportSplit::cents($r->amount));
        $paymentsSum = $payments->sum(fn ($r) => ReportSplit::cents($r->amount));

        $expectedNet = $sourcesSum + $adjustmentsSum;
        $checks = [
            'difference' => $settlement->netCents() - $expectedNet,
            'pos_check' => ReportSplit::cents($settlement->pos_revenue) - $posRows,
            'preorder_check' => ReportSplit::cents($settlement->preorder_revenue) - $preRows,
            'payment_check' => $settlement->paidCents() - $paymentsSum,
        ];

        // Total pendapatan & penyesuaian juga harus cocok dengan jumlah barisnya.
        $revenueCheck = ReportSplit::cents($settlement->revenue_total) - $sourcesSum;
        $adjustmentCheck = ReportSplit::cents($settlement->adjustments_total) - $adjustmentsSum;

        $failing = null;
        foreach ($checks + ['revenue_check' => $revenueCheck, 'adjustment_check' => $adjustmentCheck] as $name => $value) {
            if ($value !== 0) {
                $failing = $name;
                break;
            }
        }

        return [
            'sources_sum' => ReportSplit::money($sourcesSum),
            'adjustments_sum' => ReportSplit::money($adjustmentsSum),
            'expected_net' => ReportSplit::money($expectedNet),
            'net_amount' => ReportSplit::money($settlement->netCents()),
            'difference' => ReportSplit::money($checks['difference']),
            'pos_check' => ReportSplit::money($checks['pos_check']),
            'preorder_check' => ReportSplit::money($checks['preorder_check']),
            'payment_check' => ReportSplit::money($checks['payment_check']),
            'balanced' => $failing === null,
            'failing' => $failing,
        ];
    }
}
