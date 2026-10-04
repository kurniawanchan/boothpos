<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Payment;
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
 * 033-seller-recap-pos-preorder-split — kolom POS vs pre-order pada Rekap
 * Seller (US1/US2), Laba-Rugi dan Modal Seller (US3). Jaminan utamanya
 * REKONSILIASI: bagian POS + bagian pre-order == total yang sudah tampil,
 * persis (unit bulat, uang dalam sen), untuk tiap baris dan grand total.
 */
class ReportPosPreorderSplitTest extends TestCase
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

    private function assertRowReconciles(array $row): void
    {
        $this->assertSame((int) $row['total_units'], $row['pos_units'] + $row['preorder_units'], 'unit POS + pre-order != total');
        $this->assertSame(
            ReportSplit::cents($row['total_sales']),
            ReportSplit::cents($row['pos_sales']) + ReportSplit::cents($row['preorder_sales']),
            'sales POS + pre-order != total'
        );
    }

    // ===================================================================
    // US1 — Rekap Seller
    // ===================================================================

    public function test_recap_row_splits_pos_and_partially_paid_preorder_and_reconciles(): void
    {
        [$artist, $variant] = $this->seller('SPA');
        $this->posSale([[$variant, 3]]);                // 30000, 3 unit
        $this->preorder([[$variant, 2]], paid: 5000);   // 20000 subtotal, 25% terbayar -> 5000, 0,5 unit

        $row = $this->recapRows()[$artist->id];

        $this->assertSame(3, $row['pos_units']);
        $this->assertSame('30000.00', $row['pos_sales']);
        $this->assertSame('5000.00', $row['preorder_sales']);
        $this->assertSame('35000.00', $row['total_sales']);
        // total_units adalah integer hasil pembulatan (3 + 0,5 -> 4); bagian
        // pre-order adalah SISA-nya, bukan pembulatan terpisah.
        $this->assertSame(4, $row['total_units']);
        $this->assertSame(1, $row['preorder_units']);
        $this->assertRowReconciles($row);
    }

    public function test_pos_only_and_preorder_only_and_empty_sellers(): void
    {
        [$posOnly, $posVariant] = $this->seller('SPB');
        [$preOnly, $preVariant] = $this->seller('SPC');
        $empty = Artist::factory()->create(['code' => 'SPD', 'name' => 'Seller SPD']);

        $this->posSale([[$posVariant, 2]]);
        $this->preorder([[$preVariant, 1]], paid: 10000);

        $rows = $this->recapRows();

        $this->assertSame(0, $rows[$posOnly->id]['preorder_units']);
        $this->assertSame('0.00', $rows[$posOnly->id]['preorder_sales']);
        $this->assertSame(2, $rows[$posOnly->id]['pos_units']);
        $this->assertSame('20000.00', $rows[$posOnly->id]['pos_sales']);

        $this->assertSame(0, $rows[$preOnly->id]['pos_units']);
        $this->assertSame('0.00', $rows[$preOnly->id]['pos_sales']);
        $this->assertSame(1, $rows[$preOnly->id]['preorder_units']);
        $this->assertSame('10000.00', $rows[$preOnly->id]['preorder_sales']);

        // Seller aktif tanpa penjualan tetap tampil dengan nol di keempat kolom.
        $this->assertSame(0, $rows[$empty->id]['pos_units']);
        $this->assertSame(0, $rows[$empty->id]['preorder_units']);
        $this->assertSame('0.00', $rows[$empty->id]['pos_sales']);
        $this->assertSame('0.00', $rows[$empty->id]['preorder_sales']);

        $rows->each(fn ($row) => $this->assertRowReconciles($row));
    }

    public function test_existing_recap_fields_are_unchanged_by_the_split(): void
    {
        [$artist, $variant] = $this->seller('SPE');
        $this->posSale([[$variant, 1]]);
        $this->preorder([[$variant, 1]], paid: 4000);

        $row = $this->recapRows()[$artist->id];

        foreach (['id', 'artist_id', 'artist_name', 'total_sales', 'total_units', 'deduction', 'payable_amount', 'paid_amount', 'outstanding', 'status'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
        $this->assertSame('14000.00', $row['total_sales']);
        $this->assertSame('14000.00', $row['payable_amount']);
        $this->assertSame('unpaid', $row['status']);
    }

    // ----- pembulatan ------------------------------------------------------

    public function test_half_unit_and_sub_unit_preorder_fractions_never_drift_from_the_total(): void
    {
        [$half, $halfVariant] = $this->seller('SPF');
        [$tiny, $tinyVariant] = $this->seller('SPG');

        $this->preorder([[$halfVariant, 1]], paid: 5000);  // 0,5 unit -> total dibulatkan jadi 1
        $this->preorder([[$tinyVariant, 1]], paid: 3000);  // 0,3 unit -> total dibulatkan jadi 0

        $rows = $this->recapRows();

        $this->assertSame(1, $rows[$half->id]['total_units']);
        $this->assertSame(1, $rows[$half->id]['preorder_units']);
        $this->assertSame(0, $rows[$tiny->id]['total_units']);
        $this->assertSame(0, $rows[$tiny->id]['preorder_units']);
        $this->assertSame('3000.00', $rows[$tiny->id]['preorder_sales']);
        $rows->each(fn ($row) => $this->assertRowReconciles($row));
    }

    public function test_sub_cent_proration_across_three_sellers_still_reconciles_per_row(): void
    {
        [$a, $va] = $this->seller('SPH');
        [$b, $vb] = $this->seller('SPI');
        [$c, $vc] = $this->seller('SPJ');

        // Subtotal 30000, 10000 terkumpul -> tiap seller 3333,333... (bukan sen utuh).
        $this->preorder([[$va, 1], [$vb, 1], [$vc, 1]], paid: 10000);

        $rows = $this->recapRows();

        foreach ([$a, $b, $c] as $artist) {
            $this->assertSame('3333.33', $rows[$artist->id]['preorder_sales']);
            $this->assertSame('0.00', $rows[$artist->id]['pos_sales']);
            $this->assertRowReconciles($rows[$artist->id]);
        }
    }

    // ----- pengecualian ------------------------------------------------------

    public function test_cancelled_preorders_voided_orders_and_rejected_payments_are_in_neither_part(): void
    {
        [$artist, $variant] = $this->seller('SPK');

        $voided = $this->posSale([[$variant, 5]]);
        app(OrderService::class)->void($voided, 'batal', $this->owner);

        $cancelled = $this->preorder([[$variant, 1]], paid: 10000);
        app(PreorderService::class)->transitionStatus($cancelled, 'cancelled', 'batal', $this->owner);

        $rejected = $this->preorder([[$variant, 1]], paid: 10000);
        Payment::where('preorder_id', $rejected->id)->update(['verification' => 'rejected']);

        $row = $this->recapRows()[$artist->id];

        $this->assertSame(0, $row['pos_units']);
        $this->assertSame('0.00', $row['pos_sales']);
        $this->assertSame(0, $row['preorder_units']);
        $this->assertSame('0.00', $row['preorder_sales']);
        $this->assertRowReconciles($row);
    }

    // ----- mode DEMO/LIVE & otorisasi ------------------------------------------

    public function test_demo_data_never_leaks_into_the_live_parts(): void
    {
        [$artist, $variant] = $this->seller('SPL');
        $this->posSale([[$variant, 1]]);
        $this->preorder([[$variant, 1]], paid: 10000);

        ModeGate::runAs('demo', function () use ($artist) {
            $event = Event::factory()->create(['status' => 'active']);
            $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $this->cashier->id, 'status' => 'open']);
            $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $this->category->id]);
            $variant = $product->variants()->create(['sku' => 'SPLKYDEM0001', 'sell_price' => 999999, 'cost_price' => 1, 'current_stock' => 100]);
            $this->posSale([[$variant, 1]], $event, $session);
            $this->preorder([[$variant, 1]], paid: 888888, event: $event);
        });

        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'live', 'type' => 'string', 'group' => 'system']);

        $row = $this->recapRows()[$artist->id];

        $this->assertSame('10000.00', $row['pos_sales']);
        $this->assertSame('10000.00', $row['preorder_sales']);
        $this->assertRowReconciles($row);
    }

    public function test_recap_split_is_forbidden_for_cashier_and_inventory(): void
    {
        foreach (['cashier', 'inventory'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum');
            $this->getJson("/api/v1/reports/artist-settlements?event_id={$this->event->id}")->assertForbidden();
        }
    }

    // ===================================================================
    // US2 — rekonsiliasi dengan detail transaksi dan ekspor
    // ===================================================================

    private function drilldown(Artist $artist): \Illuminate\Support\Collection
    {
        return collect($this->getJson("/api/v1/reports/artist-settlements/{$artist->id}/transactions?event_id={$this->event->id}")
            ->assertOk()->json('transactions'));
    }

    private function sumCents(\Illuminate\Support\Collection $transactions, string $source): int
    {
        return $transactions->where('source', $source)
            ->sum(fn ($tx) => ReportSplit::cents($tx['amount_for_artist']));
    }

    public function test_drilldown_sums_by_kind_equal_the_pos_and_preorder_columns(): void
    {
        [$artist, $variant] = $this->seller('SQA');
        $this->posSale([[$variant, 2]]);
        $this->posSale([[$variant, 1]]);
        $this->preorder([[$variant, 2]], paid: 5000);
        $this->preorder([[$variant, 1]], paid: 10000);

        $row = $this->recapRows()[$artist->id];
        $transactions = $this->drilldown($artist);

        $this->assertSame(ReportSplit::cents($row['pos_sales']), $this->sumCents($transactions, 'order'));
        $this->assertSame(ReportSplit::cents($row['preorder_sales']), $this->sumCents($transactions, 'preorder'));
        $this->assertSame(
            (int) $row['pos_units'],
            (int) $transactions->where('source', 'order')->sum(fn ($tx) => collect($tx['items'])->sum('qty'))
        );
    }

    public function test_drilldown_rounds_each_row_so_sub_cent_preorders_may_differ_from_the_column_by_at_most_one_cent_per_row(): void
    {
        // Tiga pre-order masing-masing 3333,333... diakui untuk seller yang sama:
        // drill-down membulatkan PER BARIS (tiap transaksi tampil 3333.33), sedangkan
        // kolom memakai jumlah pecahan penuh lalu dibulatkan sekali. Selisihnya
        // murni pembulatan tampilan (<= 1 sen per baris), bukan data yang berbeda.
        [$artist, $variant] = $this->seller('SQB', price: 10000);
        [, $other1] = $this->seller('SQC');
        [, $other2] = $this->seller('SQD');
        foreach (range(1, 3) as $i) {
            $this->preorder([[$variant, 1], [$other1, 1], [$other2, 1]], paid: 10000);
        }

        $row = $this->recapRows()[$artist->id];
        $transactions = $this->drilldown($artist);

        $column = ReportSplit::cents($row['preorder_sales']);
        $detail = $this->sumCents($transactions, 'preorder');

        $this->assertSame(3, $transactions->where('source', 'preorder')->count());
        $this->assertLessThanOrEqual(3, abs($column - $detail));
        $this->assertSame(0, ReportSplit::cents($row['pos_sales']));
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

    public function test_recap_export_keeps_existing_headings_in_place_and_appends_the_split_columns(): void
    {
        [$artist, $variant] = $this->seller('SQE');
        $this->posSale([[$variant, 3]]);
        $this->preorder([[$variant, 2]], paid: 5000);

        $api = $this->recapRows()[$artist->id];
        $sheet = $this->exportSheet();
        $headings = $sheet[0];

        $this->assertSame(
            ['id', 'artist_id', 'artist_name', 'total_sales', 'total_units', 'deduction', 'payable_amount', 'paid_amount', 'outstanding', 'status'],
            array_slice($headings, 0, 10),
            'bentuk lama harus tetap di tempatnya'
        );
        $this->assertSame(['pos_units', 'preorder_units', 'pos_sales', 'preorder_sales'], array_slice($headings, 10, 4));

        $row = array_combine($headings, collect($sheet)->first(fn ($r) => (string) $r[array_search('artist_id', $headings)] === (string) $artist->id));

        // Excel menyimpan angka sebagai numerik ("30000.00" -> 30000), jadi banding
        // sebagai angka, bukan string.
        foreach (['pos_units', 'preorder_units', 'pos_sales', 'preorder_sales'] as $key) {
            $this->assertEqualsWithDelta((float) $api[$key], (float) $row[$key], 0.001, $key);
        }
    }

    public function test_recap_export_writes_zero_units_as_zero_not_as_a_blank_cell(): void
    {
        [$posOnly, $posVariant] = $this->seller('SQF');
        $this->posSale([[$posVariant, 1]]);

        $sheet = $this->exportSheet();
        $headings = $sheet[0];
        $row = array_combine($headings, collect($sheet)->first(fn ($r) => (string) $r[array_search('artist_id', $headings)] === (string) $posOnly->id));

        $this->assertNotNull($row['preorder_units']);
        $this->assertEquals(0, $row['preorder_units']);
        $this->assertEquals(1, $row['pos_units']);
    }

    public function test_recap_export_is_forbidden_for_non_owner_admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');
        $this->get("/api/v1/reports/artist-settlements/export?event_id={$this->event->id}")->assertForbidden();
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

    public function test_seller_cost_sales_total_equals_the_seller_recap_total_for_every_seller(): void
    {
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
            $this->assertSame($recap[$artistId]['total_sales'], $row['total_sales'], "seller {$artistId}");
        }
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
