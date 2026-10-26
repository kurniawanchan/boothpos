<?php

namespace Tests\Feature\SellerSettlement;

use App\Services\SellerSettlementBuilder;
use App\Support\ReportSplit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsSettlementFixtures;
use Tests\TestCase;

/**
 * 076 (SC-006) — settlement dan laporan Modal Seller memakai aturan pendapatan
 * yang sama (PreorderRecognizedRevenue). POS harus persis sama; bagian
 * pre-order boleh berbeda paling banyak 1 sen per baris pre-order, karena
 * settlement membulatkan per transaksi sedangkan laporan membulatkan agregat.
 */
class SellerSettlementReportParityTest extends TestCase
{
    use BuildsSettlementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSettlementWorld();
    }

    public function test_settlement_revenue_matches_the_seller_cost_report(): void
    {
        [$a, $va] = $this->seller('AAA', 10000, 4000);
        [$b, $vb] = $this->seller('BBB', 3333, 1000);

        $this->posSale([[$va, 3], [$vb, 2]]);
        $this->posSale([[$va, 1]]);
        $preorderRows = 0;
        foreach ([[$va, 2, 7000], [$vb, 3, 1234.56], [$va, 1, 10000]] as [$variant, $qty, $paid]) {
            $this->preorder([[$variant, $qty]], $paid);
            $preorderRows++;
        }
        $this->preorder([[$va, 1], [$vb, 1]], 4567.89);

        $report = collect($this->getJson("/api/v1/reports/artist-profit?event_id={$this->event->id}")
            ->assertOk()->json('data'))->keyBy('artist_id');

        foreach ([$a, $b] as $seller) {
            $built = app(SellerSettlementBuilder::class)->build($this->event->id, $seller->id);
            $row = $report[$seller->id];
            $preRows = count(array_filter($built['sources'], fn ($s) => $s['source_type'] === 'preorder'));

            $this->assertSame($row['sales_pos'], $built['totals']['pos_revenue'], "POS {$seller->code} harus persis sama");

            $diff = abs(ReportSplit::cents($row['sales_preorder']) - ReportSplit::cents($built['totals']['preorder_revenue']));
            $this->assertLessThanOrEqual($preRows, $diff, "pre-order {$seller->code}: selisih {$diff} sen untuk {$preRows} baris");
        }

        $this->assertGreaterThan(0, $preorderRows);
    }
}
