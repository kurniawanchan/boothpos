<?php

namespace App\Services;

use App\Support\ModeGate;
use App\Support\PreorderRecognizedRevenue;
use App\Support\ReportSplit;
use Illuminate\Support\Facades\DB;

/**
 * 076-settlement-tab-session-branding — menyusun isi settlement (baris sumber
 * per transaksi + total + memo) dari data hidup untuk SATU (event, seller).
 * MURNI MEMBACA: tidak menulis apa pun; SellerSettlementService yang
 * menyimpannya sebagai snapshot saat draft dibuat/di-refresh/difinalkan.
 *
 * Aturan pendapatan TIDAK diciptakan ulang di sini:
 *  - POS      = order_items dari order 'completed' (order void tidak ikut),
 *               sama dengan ReportController::artistProfit();
 *  - pre-order = PreorderRecognizedRevenue (porsi yang benar-benar terkumpul,
 *               pre-order batal tidak ikut) — rumus yang sama dengan laporan.
 *
 * PEMBULATAN: tiap baris sumber dibulatkan SEKALI ke sen, lalu total adalah
 * jumlah baris-baris itu (dalam sen, integer). Akibatnya laporan settlement
 * selalu bisa dijumlahkan persis; selisihnya terhadap laporan yang hanya
 * membulatkan agregat paling banyak 1 sen per baris pre-order (dan laporan
 * itu sendiri memang tidak mengubah angkanya). Jangan "memperbaiki" dengan
 * membulatkan total saja — baris tidak akan lagi menjumlah ke total.
 *
 * Query tangan-tulis melewati global scope Eloquent, jadi data_mode
 * difilter eksplisit (003-seed-demo-live).
 */
class SellerSettlementBuilder
{
    /**
     * @return array{
     *   sources: list<array<string, mixed>>,
     *   totals: array<string, int|string>,
     * }
     */
    public function build(int $eventId, int $artistId): array
    {
        $sources = [];
        $posCents = 0;
        $posUnits = 0;
        $posCostCents = 0;

        foreach ($this->posRows($eventId, $artistId) as $row) {
            $amount = ReportSplit::cents($row->gross);
            $posCents += $amount;
            $posUnits += (int) $row->units;
            $posCostCents += ReportSplit::cents($row->cost);

            $sources[] = [
                'source_type' => 'order',
                'source_id' => (int) $row->source_id,
                'source_number' => $row->source_number,
                'source_date' => $row->source_date,
                'units' => (int) $row->units,
                'gross_amount' => ReportSplit::money($amount),
                'amount' => ReportSplit::money($amount),
                'uncollected_amount' => '0.00',
                'cost_amount' => ReportSplit::money(ReportSplit::cents($row->cost)),
            ];
        }

        $preCents = 0;
        $preUnits = 0;
        $preCostCents = 0;
        $uncollectedCents = 0;

        foreach ($this->preorderRows($eventId, $artistId) as $row) {
            // Memo "belum terkumpul" mencakup SEMUA pre-order tak batal milik seller,
            // termasuk yang belum dibayar sama sekali (tidak punya baris sumber).
            $uncollectedCents += ReportSplit::cents($row->uncollected);

            $amount = ReportSplit::cents($row->recognized);
            if ($amount <= 0) {
                continue; // belum ada uang terkumpul → tidak ada pendapatan diakui
            }

            $preCents += $amount;
            $preUnits += (int) $row->units;
            $preCostCents += ReportSplit::cents($row->cost);

            $sources[] = [
                'source_type' => 'preorder',
                'source_id' => (int) $row->source_id,
                'source_number' => $row->source_number,
                'source_date' => $row->source_date,
                'units' => (int) $row->units,
                'gross_amount' => ReportSplit::money(ReportSplit::cents($row->gross)),
                'amount' => ReportSplit::money($amount),
                'uncollected_amount' => ReportSplit::money(ReportSplit::cents($row->uncollected)),
                'cost_amount' => ReportSplit::money(ReportSplit::cents($row->cost)),
            ];
        }

        return [
            'sources' => $sources,
            'totals' => [
                'pos_units' => $posUnits,
                'pos_revenue' => ReportSplit::money($posCents),
                'preorder_units' => $preUnits,
                'preorder_revenue' => ReportSplit::money($preCents),
                'revenue_total' => ReportSplit::money($posCents + $preCents),
                'memo_preorder_uncollected' => ReportSplit::money($uncollectedCents),
                'memo_cogs_pos' => ReportSplit::money($posCostCents),
                'memo_cogs_preorder' => ReportSplit::money($preCostCents),
            ],
        ];
    }

    /** Satu baris per order 'completed' yang memuat item seller ini. */
    private function posRows(int $eventId, int $artistId)
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.event_id', $eventId)
            ->where('orders.status', 'completed')
            ->where('order_items.artist_id', $artistId)
            ->where('order_items.data_mode', ModeGate::current())
            ->selectRaw('
                orders.id as source_id,
                orders.order_number as source_number,
                orders.created_at as source_date,
                SUM(order_items.qty) as units,
                SUM(order_items.line_total) as gross,
                SUM(order_items.cost_price * order_items.qty) as cost
            ')
            ->groupBy('orders.id', 'orders.order_number', 'orders.created_at')
            ->orderBy('orders.created_at')
            ->orderBy('orders.id')
            ->get();
    }

    /** Satu baris per pre-order tak batal yang memuat item seller ini. */
    private function preorderRows(int $eventId, int $artistId)
    {
        // DIBATASI maks. 1 (khusus settlement). Rumus laporan membagi terkumpul dengan subtotal,
        // sedangkan terkumpul bisa mencapai total_amount (= subtotal + ongkir − diskon): tanpa
        // batas, pre-order lunas beserta ongkir menghasilkan pecahan > 1 sehingga seller DIKREDIT
        // ongkir dan memo "belum terkumpul" menjadi negatif. Ini uang yang dibayarkan, jadi tidak
        // boleh melebihi nilai barang. (Laporan tetap memakai rumus aslinya; lihat CLAUDE.md.)
        $fraction = 'LEAST(1, ('.PreorderRecognizedRevenue::FRACTION_EXPR.'))';

        return PreorderRecognizedRevenue::query($eventId)
            ->where('preorder_items.artist_id', $artistId)
            ->selectRaw("
                preorders.id as source_id,
                preorders.preorder_number as source_number,
                preorders.created_at as source_date,
                SUM(preorder_items.qty) as units,
                SUM(preorder_items.line_total) as gross,
                SUM(preorder_items.line_total * ({$fraction})) as recognized,
                SUM(preorder_items.line_total * (1 - ({$fraction}))) as uncollected,
                SUM(preorder_items.cost_price * preorder_items.qty * ({$fraction})) as cost
            ")
            ->groupBy('preorders.id', 'preorders.preorder_number', 'preorders.created_at')
            ->orderBy('preorders.created_at')
            ->orderBy('preorders.id')
            ->get();
    }
}
