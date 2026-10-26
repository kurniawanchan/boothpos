<?php

namespace Tests\Feature\SellerSettlement;

use App\Services\SellerSettlementBuilder;
use App\Support\ReportSplit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSettlementFixtures;
use Tests\TestCase;

/**
 * 076 (US2) — aturan pendapatan yang dibentuk SellerSettlementBuilder.
 * Builder murni membaca; di sini diuji lewat data yang dibuat service nyata.
 */
class SellerSettlementBuilderTest extends TestCase
{
    use BuildsSettlementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSettlementWorld();
    }

    private function build($artist): array
    {
        return app(SellerSettlementBuilder::class)->build($this->event->id, $artist->id);
    }

    public function test_pos_revenue_units_and_cost_memo_come_from_completed_orders(): void
    {
        [$a, $va] = $this->seller('AAA', 10000, 4000);
        $this->posSale([[$va, 3]]);
        $this->posSale([[$va, 1]]);

        $built = $this->build($a);

        $this->assertSame('40000.00', $built['totals']['pos_revenue']);
        $this->assertSame(4, $built['totals']['pos_units']);
        $this->assertSame('16000.00', $built['totals']['memo_cogs_pos']);
        $this->assertSame('0.00', $built['totals']['preorder_revenue']);
        $this->assertSame('40000.00', $built['totals']['revenue_total']);
        $this->assertCount(2, $built['sources']);
        $this->assertSame('order', $built['sources'][0]['source_type']);
    }

    public function test_a_voided_order_contributes_nothing(): void
    {
        [$a, $va] = $this->seller('AAA');
        $keep = $this->posSale([[$va, 1]]);
        $void = $this->posSale([[$va, 5]]);
        $this->voidOrder($void);

        $built = $this->build($a);

        $this->assertSame('10000.00', $built['totals']['pos_revenue']);
        $this->assertSame([$keep->order_number], array_column($built['sources'], 'source_number'));
    }

    public function test_only_the_requested_seller_is_included_even_inside_a_mixed_order(): void
    {
        [$a, $va] = $this->seller('AAA', 10000);
        [$b, $vb] = $this->seller('BBB', 2500);
        $this->posSale([[$va, 1], [$vb, 2]]);

        $this->assertSame('10000.00', $this->build($a)['totals']['pos_revenue']);
        $this->assertSame('5000.00', $this->build($b)['totals']['pos_revenue']);
    }

    public function test_a_partly_paid_preorder_counts_only_the_collected_fraction(): void
    {
        [$a, $va] = $this->seller('AAA', 10000);
        $pre = $this->preorder([[$va, 2]], 5000); // subtotal 20000, terkumpul 5000 → 25%

        $built = $this->build($a);

        $this->assertSame('5000.00', $built['totals']['preorder_revenue']);
        $this->assertSame('15000.00', $built['totals']['memo_preorder_uncollected']);
        $this->assertSame(2, $built['totals']['preorder_units']);
        $row = collect($built['sources'])->firstWhere('source_type', 'preorder');
        $this->assertSame($pre->preorder_number, $row['source_number']);
        $this->assertSame('20000.00', $row['gross_amount']);
        $this->assertSame('5000.00', $row['amount']);
        $this->assertSame('15000.00', $row['uncollected_amount']);
    }

    public function test_an_unpaid_preorder_has_no_source_row_but_shows_in_the_uncollected_memo(): void
    {
        [$a, $va] = $this->seller('AAA', 10000);
        $this->preorder([[$va, 1]], 0);

        $built = $this->build($a);

        $this->assertSame([], $built['sources']);
        $this->assertSame('0.00', $built['totals']['preorder_revenue']);
        $this->assertSame('10000.00', $built['totals']['memo_preorder_uncollected']);
    }

    public function test_a_cancelled_preorder_is_excluded_entirely(): void
    {
        [$a, $va] = $this->seller('AAA', 10000);
        $pre = $this->preorder([[$va, 1]], 4000);
        $this->cancelPreorder($pre);

        $built = $this->build($a);

        $this->assertSame([], $built['sources']);
        $this->assertSame('0.00', $built['totals']['memo_preorder_uncollected']);
    }

    public function test_rejected_payments_do_not_count_as_collected(): void
    {
        [$a, $va] = $this->seller('AAA', 10000);
        $pre = $this->preorder([[$va, 1]], 4000);
        DB::table('payments')->where('preorder_id', $pre->id)->update(['verification' => 'rejected']);

        $built = $this->build($a);

        $this->assertSame('0.00', $built['totals']['preorder_revenue']);
        $this->assertSame([], $built['sources']);
    }

    public function test_each_row_is_rounded_once_and_totals_are_the_exact_sum_of_the_rows(): void
    {
        // Satu pre-order dua seller: subtotal 1500, terkumpul 1000.01 → pecahan 0,666673…
        [$a, $va] = $this->seller('AAA', 1000);
        [$b, $vb] = $this->seller('BBB', 500);
        $this->preorder([[$va, 1], [$vb, 1]], 1000.01);

        $builtA = $this->build($a);
        $builtB = $this->build($b);

        $this->assertSame('666.67', $builtA['totals']['preorder_revenue']);
        $this->assertSame('333.34', $builtB['totals']['preorder_revenue']);
        $this->assertSame(
            ReportSplit::cents('1000.01'),
            ReportSplit::cents($builtA['totals']['preorder_revenue']) + ReportSplit::cents($builtB['totals']['preorder_revenue']),
        );

        foreach ([$builtA, $builtB] as $built) {
            $sum = array_sum(array_map(fn ($r) => ReportSplit::cents($r['amount']), $built['sources']));
            $this->assertSame($sum, ReportSplit::cents($built['totals']['revenue_total']));
        }
    }

    public function test_shipping_paid_on_top_of_the_items_is_never_credited_to_the_seller(): void
    {
        // Pre-order 10.000 + ongkir 20.000 dibayar lunas 30.000: pecahan mentah laporan = 3,0.
        [$a, $va] = $this->seller('AAA', 10000);
        $pre = $this->preorder([[$va, 1]], 10000);
        DB::table('preorders')->where('id', $pre->id)->update(['shipping_cost' => '20000.00', 'total_amount' => '30000.00']);
        app(\App\Services\PreorderService::class)->recordPayment($pre->fresh(), [
            'method' => 'cash', 'channel_id' => null, 'purpose' => 'full', 'amount' => 20000,
        ]);

        $built = $this->build($a);

        $this->assertSame('10000.00', $built['totals']['preorder_revenue'], 'hanya nilai barang, bukan ongkir');
        $this->assertSame('0.00', $built['totals']['memo_preorder_uncollected'], 'tidak boleh negatif');
        $this->assertSame('4000.00', $built['totals']['memo_cogs_preorder'], 'biaya pokok ikut dibatasi');
    }

    public function test_rows_of_the_other_data_mode_never_leak_in(): void
    {
        [$a, $va] = $this->seller('AAA');
        $this->posSale([[$va, 2]]);
        DB::table('order_items')->update(['data_mode' => 'demo']);

        $this->assertSame('0.00', $this->build($a)['totals']['pos_revenue']);
        $this->assertSame([], $this->build($a)['sources']);
    }

    public function test_activity_in_another_event_is_not_included(): void
    {
        [$a, $va] = $this->seller('AAA');
        $other = \App\Models\Event::factory()->create(['status' => 'active']);
        $this->preorder([[$va, 1]], 10000, $other);
        $this->posSale([[$va, 1]]);

        $built = $this->build($a);

        $this->assertSame('10000.00', $built['totals']['pos_revenue']);
        $this->assertSame('0.00', $built['totals']['preorder_revenue']);
    }
}
