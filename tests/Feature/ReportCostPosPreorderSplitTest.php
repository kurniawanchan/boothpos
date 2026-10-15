<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Preorder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PreorderService;
use App\Support\ModeGate;
use App\Support\ReportSplit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 033-seller-recap-pos-preorder-split — pemisahan POS vs pre-order pada
 * Laba-Rugi dan Modal Seller (US3). Jaminan utamanya REKONSILIASI: bagian
 * POS + bagian pre-order == total yang sudah tampil, persis (uang dalam sen).
 * Sejak 040 Rekap Seller TIDAK lagi memuat pre-order, jadi pengujian Rekap
 * pindah ke SellerRecapPosOnlyTest; berkas ini hanya menyisakan laporan modal
 * yang memang masih memisahkan POS dan pre-order.
 */
class ReportCostPosPreorderSplitTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    private Event $event;

    private CashierSession $session;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->event = Event::factory()->create(['status' => 'active']);
        $this->session = CashierSession::factory()->create([
            'event_id' => $this->event->id, 'user_id' => $this->cashier->id, 'status' => 'open',
        ]);
        $this->category = Category::factory()->create();

        $this->actingAs($this->owner, 'sanctum');
    }

    // ----- fixtures -----------------------------------------------------

    /** @return array{0: Artist, 1: ProductVariant} */
    private function seller(string $code, float $price = 10000, float $cost = 4000, ?string $name = null): array
    {
        $artist = Artist::factory()->create(['code' => $code, 'name' => $name ?? "Seller {$code}"]);
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $this->category->id]);
        $variant = $product->variants()->create([
            'sku' => $code.'KYAAA0001', 'sell_price' => $price, 'cost_price' => $cost, 'current_stock' => 1000,
        ]);

        return [$artist, $variant];
    }

    private function posSale(array $lines, ?Event $event = null, ?CashierSession $session = null)
    {
        $total = collect($lines)->sum(fn ($l) => $l[0]->sell_price * $l[1]);

        return app(OrderService::class)->create([
            'session_id' => ($session ?? $this->session)->id,
            'local_ref' => (string) Str::uuid(),
            'items' => collect($lines)->map(fn ($l) => ['variant_id' => $l[0]->id, 'qty' => $l[1]])->all(),
            'payments' => [['method' => 'cash', 'amount' => $total]],
        ], $this->cashier);
    }

    private function preorder(array $lines, float $paid, ?Event $event = null): Preorder
    {
        $service = app(PreorderService::class);
        $preorder = $service->create([
            'event_id' => ($event ?? $this->event)->id,
            'customer_id' => Customer::factory()->create()->id,
            'fulfillment' => 'pickup',
            'items' => collect($lines)->map(fn ($l) => ['variant_id' => $l[0]->id, 'qty' => $l[1]])->all(),
        ], $this->owner);

        if ($paid > 0) {
            $service->recordPayment($preorder, [
                'method' => 'cash', 'channel_id' => null, 'purpose' => 'down_payment', 'amount' => $paid,
            ]);
        }

        return $preorder->fresh(['items', 'payments']);
    }

    private function recapRows(): \Illuminate\Support\Collection
    {
        $response = $this->getJson("/api/v1/reports/artist-settlements?event_id={$this->event->id}")->assertOk();

        return collect($response->json('data'))->keyBy('artist_id');
    }

    private function exportSheet(string $report = 'artist-settlements'): array
    {
        $response = $this->get("/api/v1/reports/{$report}/export?event_id={$this->event->id}");
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'split').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getSheet(0);
        $rows = $sheet->toArray(null, true, true, false);
        unlink($tmp);

        return $rows;
    }

    // ===================================================================
    // US3 — Laba-Rugi (profit) dan Modal Seller (artist-profit)
    // ===================================================================

    private function profit(): array
    {
        return $this->getJson("/api/v1/reports/profit?event_id={$this->event->id}")->assertOk()->json();
    }

    private function sellerCostRows(): \Illuminate\Support\Collection
    {
        return collect($this->getJson("/api/v1/reports/artist-profit?event_id={$this->event->id}")->assertOk()->json('data'));
    }

    private function assertCentsAdd(string $total, string $pos, string $pre, string $what): void
    {
        $this->assertSame(ReportSplit::cents($total), ReportSplit::cents($pos) + ReportSplit::cents($pre), $what);
    }

    public function test_profit_splits_revenue_cost_and_gross_profit_and_reconciles_with_the_totals(): void
    {
        [, $variant] = $this->seller('SRA', price: 10000, cost: 4000);
        $this->posSale([[$variant, 3]]);                // pendapatan 30000, modal 12000
        $this->preorder([[$variant, 2]], paid: 5000);   // 25% terbayar: pendapatan 5000, modal 2000

        $p = $this->profit();

        $this->assertSame('30000.00', $p['revenue_pos']);
        $this->assertSame('5000.00', $p['revenue_preorder']);
        $this->assertSame('12000.00', $p['cost_of_goods_pos']);
        $this->assertSame('2000.00', $p['cost_of_goods_preorder']);
        $this->assertSame('18000.00', $p['gross_profit_pos']);
        $this->assertSame('3000.00', $p['gross_profit_preorder']);

        $this->assertCentsAdd($p['revenue'], $p['revenue_pos'], $p['revenue_preorder'], 'revenue');
        $this->assertCentsAdd($p['cost_of_goods'], $p['cost_of_goods_pos'], $p['cost_of_goods_preorder'], 'cost');
        $this->assertCentsAdd($p['gross_profit'], $p['gross_profit_pos'], $p['gross_profit_preorder'], 'gross');
    }

    public function test_profit_existing_figures_and_event_level_costs_are_unchanged_and_not_split(): void
    {
        [, $variant] = $this->seller('SRB', price: 10000, cost: 4000);
        $this->event->update(['event_cost' => 1500]);
        $this->posSale([[$variant, 1]]);
        $this->preorder([[$variant, 1]], paid: 4000);

        $p = $this->profit();

        $this->assertSame('14000.00', $p['revenue']);
        $this->assertSame('5600.00', $p['cost_of_goods']);
        $this->assertSame('8400.00', $p['gross_profit']);
        $this->assertSame('1500.00', $p['event_cost']);
        $this->assertSame('6900.00', $p['net_profit']);
        $this->assertArrayNotHasKey('event_cost_pos', $p);
        $this->assertArrayNotHasKey('net_profit_pos', $p);
    }

    public function test_profit_without_preorders_has_zero_preorder_parts_and_unchanged_totals(): void
    {
        [, $variant] = $this->seller('SRC', price: 10000, cost: 4000);
        $this->posSale([[$variant, 2]]);

        $p = $this->profit();

        $this->assertSame('20000.00', $p['revenue']);
        $this->assertSame('20000.00', $p['revenue_pos']);
        $this->assertSame('0.00', $p['revenue_preorder']);
        $this->assertSame('0.00', $p['cost_of_goods_preorder']);
        $this->assertSame('0.00', $p['gross_profit_preorder']);
    }

    public function test_profit_counts_only_the_paid_portion_of_a_preorder_for_revenue_and_cost(): void
    {
        [, $variant] = $this->seller('SRD', price: 10000, cost: 4000);
        $this->preorder([[$variant, 1]], paid: 4000);   // 40%

        $p = $this->profit();

        $this->assertSame('4000.00', $p['revenue_preorder']);
        $this->assertSame('1600.00', $p['cost_of_goods_preorder']);
        $this->assertSame('2400.00', $p['gross_profit_preorder']);
        $this->assertSame('0.00', $p['revenue_pos']);
    }

    public function test_seller_cost_shows_pos_preorder_and_total_and_lists_preorder_only_sellers(): void
    {
        [$a, $va] = $this->seller('SRE', 10000, 4000, 'Aaa Seller');
        [$b, $vb] = $this->seller('SRF', 20000, 8000, 'Bbb Seller');
        [$c, $vc] = $this->seller('SRG', 10000, 4000, 'Ccc Seller');

        $this->posSale([[$va, 3]]);                     // A POS: 30000 / modal 12000
        $this->preorder([[$va, 2]], paid: 5000);        // A pre-order 25%: 5000 / modal 2000
        $this->posSale([[$vb, 1]]);                     // B hanya POS: 20000 / 8000
        $this->preorder([[$vc, 1]], paid: 10000);       // C hanya pre-order: 10000 / 4000

        $rows = $this->sellerCostRows();

        $this->assertSame(['Aaa Seller', 'Bbb Seller', 'Ccc Seller'], $rows->pluck('artist_name')->all(), 'urut nama, seller pre-order-saja ikut tampil');
        $byId = $rows->keyBy('artist_id');

        $this->assertSame('30000.00', $byId[$a->id]['sales_pos']);
        $this->assertSame('5000.00', $byId[$a->id]['sales_preorder']);
        $this->assertSame('35000.00', $byId[$a->id]['total_sales']);
        $this->assertSame('14000.00', $byId[$a->id]['modal']);
        $this->assertSame('21000.00', $byId[$a->id]['gross_profit']);

        // Bagian POS sama dengan angka SEBELUM fitur ini; bagian pre-order nol.
        $this->assertSame('20000.00', $byId[$b->id]['total_sales']);
        $this->assertSame('20000.00', $byId[$b->id]['sales_pos']);
        $this->assertSame('0.00', $byId[$b->id]['sales_preorder']);
        $this->assertSame('8000.00', $byId[$b->id]['modal']);

        $this->assertSame('0.00', $byId[$c->id]['sales_pos']);
        $this->assertSame('10000.00', $byId[$c->id]['sales_preorder']);
        $this->assertSame('4000.00', $byId[$c->id]['modal_preorder']);
        $this->assertSame('6000.00', $byId[$c->id]['gross_profit_preorder']);

        foreach ($rows as $row) {
            $this->assertCentsAdd($row['total_sales'], $row['sales_pos'], $row['sales_preorder'], 'sales '.$row['artist_name']);
            $this->assertCentsAdd($row['modal'], $row['modal_pos'], $row['modal_preorder'], 'modal '.$row['artist_name']);
            $this->assertCentsAdd($row['gross_profit'], $row['gross_profit_pos'], $row['gross_profit_preorder'], 'gross '.$row['artist_name']);
        }
    }

    public function test_seller_cost_pos_part_equals_the_pos_only_seller_recap_but_its_total_still_adds_preorders(): void
    {
        // 040: Rekap Seller kini POS-saja, sedangkan Modal Seller tetap memuat bagian
        // pre-order yang terbayar (033) — jadi total keduanya SENGAJA tidak lagi sama;
        // yang tetap sama adalah bagian POS Modal Seller dengan penjualan di Rekap.
        [, $va] = $this->seller('SRH');
        [, $vb] = $this->seller('SRI');
        [, $vc] = $this->seller('SRJ');
        $this->posSale([[$va, 2]]);
        $this->preorder([[$va, 1], [$vb, 1], [$vc, 1]], paid: 10000);   // 3333,33.. per seller
        $this->preorder([[$vb, 2]], paid: 7000);

        $recap = $this->recapRows();
        $cost = $this->sellerCostRows()->keyBy('artist_id');

        $this->assertNotEmpty($cost);
        foreach ($cost as $artistId => $row) {
            $this->assertSame($recap[$artistId]['total_sales'], $row['sales_pos'], "seller {$artistId}");
            $this->assertCentsAdd($row['total_sales'], $row['sales_pos'], $row['sales_preorder'], "seller {$artistId}");
        }
        $this->assertTrue(
            $cost->contains(fn ($row) => $row['sales_preorder'] !== '0.00'),
            'Modal Seller tetap memuat pre-order, jadi totalnya berbeda dari Rekap'
        );
    }

    public function test_seller_cost_excludes_cancelled_preorders_and_voided_orders_and_isolates_demo_mode(): void
    {
        [$artist, $variant] = $this->seller('SRK');

        $voided = $this->posSale([[$variant, 4]]);
        app(OrderService::class)->void($voided, 'batal', $this->owner);
        $cancelled = $this->preorder([[$variant, 1]], paid: 10000);
        app(PreorderService::class)->transitionStatus($cancelled, 'cancelled', 'batal', $this->owner);

        ModeGate::runAs('demo', function () use ($artist) {
            $event = Event::factory()->create(['status' => 'active']);
            $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $this->cashier->id, 'status' => 'open']);
            $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $this->category->id]);
            $demo = $product->variants()->create(['sku' => 'SRKKYDEM0001', 'sell_price' => 999999, 'cost_price' => 1, 'current_stock' => 100]);
            $this->posSale([[$demo, 1]], $event, $session);
            $this->preorder([[$demo, 1]], paid: 888888, event: $event);
        });
        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'live', 'type' => 'string', 'group' => 'system']);

        $this->assertCount(0, $this->sellerCostRows(), 'tidak ada penjualan sah di event LIVE ini');
    }

    public function test_cost_reports_are_forbidden_for_cashier_and_inventory(): void
    {
        foreach (['cashier', 'inventory'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum');
            foreach (['profit', 'artist-profit', 'profit/export', 'artist-profit/export'] as $path) {
                $url = str_contains($path, 'export') ? "/api/v1/reports/{$path}" : "/api/v1/reports/{$path}";
                $this->get("{$url}?event_id={$this->event->id}")->assertForbidden();
            }
        }
    }

    public function test_profit_and_seller_cost_exports_carry_the_new_columns(): void
    {
        [$artist, $variant] = $this->seller('SRL', price: 10000, cost: 4000);
        $this->posSale([[$variant, 3]]);
        $this->preorder([[$variant, 2]], paid: 5000);

        $profit = $this->exportSheet('profit');
        $headings = $profit[0];
        foreach (['revenue_pos', 'revenue_preorder', 'cost_of_goods_pos', 'cost_of_goods_preorder', 'gross_profit_pos', 'gross_profit_preorder'] as $key) {
            $this->assertContains($key, $headings, $key);
        }
        $row = array_combine($headings, $profit[1]);
        $this->assertEqualsWithDelta(30000.0, (float) $row['revenue_pos'], 0.001);
        $this->assertEqualsWithDelta(5000.0, (float) $row['revenue_preorder'], 0.001);

        $cost = $this->exportSheet('artist-profit');
        $headings = $cost[0];
        foreach (['sales_pos', 'sales_preorder', 'modal_pos', 'modal_preorder', 'gross_profit_pos', 'gross_profit_preorder'] as $key) {
            $this->assertContains($key, $headings, $key);
        }
        $row = array_combine($headings, $cost[1]);
        $this->assertEqualsWithDelta(35000.0, (float) $row['total_sales'], 0.001);
        $this->assertEqualsWithDelta(5000.0, (float) $row['sales_preorder'], 0.001);
    }
}
