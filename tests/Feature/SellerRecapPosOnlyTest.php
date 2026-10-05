<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\ArtistSettlement;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 040-recap-pos-transactions-only — Rekap Seller HANYA menghitung penjualan POS.
 * Membalik keputusan 033 (rekap = POS + bagian pre-order yang sudah terbayar):
 * settlement tersimpan (total_sales/total_units/payable), Payable/Outstanding,
 * "Record payment", penutupan event, dan ekspor semuanya POS-saja, supaya angka
 * yang harus dibayar ke seller sama dengan kolom Penjualan di layar.
 */
class SellerRecapPosOnlyTest extends TestCase
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
    private function seller(string $code, float $price = 10000, float $cost = 4000): array
    {
        $artist = Artist::factory()->create(['code' => $code, 'name' => "Seller {$code}"]);
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $this->category->id]);
        $variant = $product->variants()->create([
            'sku' => $code.'KYAAA0001', 'sell_price' => $price, 'cost_price' => $cost, 'current_stock' => 1000,
        ]);

        return [$artist, $variant];
    }

    private function posSale(array $lines, ?CashierSession $session = null)
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

    private function exportSheets(): array
    {
        $response = $this->get("/api/v1/reports/artist-settlements/export?event_id={$this->event->id}");
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'recap').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
        $sheets = [
            'summary' => $book->getSheet(0)->toArray(null, true, true, false),
            'detail' => $book->getSheet(1)->toArray(null, true, true, false),
        ];
        unlink($tmp);

        return $sheets;
    }

    // ===================================================================
    // US1 — baris rekap, kolom, Grand Total
    // ===================================================================

    public function test_recap_counts_only_pos_sales_and_ignores_preorders_entirely(): void
    {
        [$artist, $variant] = $this->seller('SPA');
        $this->posSale([[$variant, 3]]);                // 30000, 3 unit
        $this->preorder([[$variant, 2]], paid: 5000);   // dulu menambah 5000 / ~0,5 unit ke rekap

        $row = $this->recapRows()[$artist->id];

        $this->assertSame('30000.00', $row['total_sales']);
        $this->assertSame(3, $row['total_units']);
        $this->assertSame('30000.00', $row['payable_amount']);
    }

    public function test_the_033_pos_and_preorder_split_fields_are_gone_from_the_response(): void
    {
        [$artist, $variant] = $this->seller('SPB');
        $this->posSale([[$variant, 1]]);

        $row = $this->recapRows()[$artist->id];

        foreach (['pos_units', 'preorder_units', 'pos_sales', 'preorder_sales'] as $removed) {
            $this->assertArrayNotHasKey($removed, $row, $removed);
        }
        foreach (['id', 'artist_id', 'artist_name', 'total_sales', 'total_units', 'deduction', 'payable_amount', 'paid_amount', 'outstanding', 'status'] as $kept) {
            $this->assertArrayHasKey($kept, $row, $kept);
        }
    }

    public function test_a_seller_with_one_pos_unit_and_a_huge_paid_preorder_shows_only_the_pos_unit(): void
    {
        // Bentuk dari tangkapan layar yang memicu fitur ini: 1 unit POS (Rp 30.000)
        // dan 82 unit pre-order — rekap dulu menampilkan 83 unit / Rp 1.269.000.
        [$artist, $variant] = $this->seller('SPC', price: 30000);
        $this->posSale([[$variant, 1]]);
        $this->preorder([[$variant, 82]], paid: 82 * 30000);

        $row = $this->recapRows()[$artist->id];

        $this->assertSame(1, $row['total_units']);
        $this->assertSame('30000.00', $row['total_sales']);
    }

    public function test_preorder_only_and_empty_sellers_are_still_listed_with_zeros(): void
    {
        [$preOnly, $preVariant] = $this->seller('SPD');
        $empty = Artist::factory()->create(['code' => 'SPE', 'name' => 'Seller SPE']);
        $this->preorder([[$preVariant, 1]], paid: 10000);

        $rows = $this->recapRows();

        foreach ([$preOnly, $empty] as $artist) {
            $this->assertTrue($rows->has($artist->id), "seller {$artist->id} harus tetap tampil");
            $this->assertSame('0.00', $rows[$artist->id]['total_sales']);
            $this->assertSame(0, $rows[$artist->id]['total_units']);
            $this->assertSame('0.00', $rows[$artist->id]['payable_amount']);
            $this->assertSame('0.00', $rows[$artist->id]['outstanding']);
        }
    }

    public function test_voided_pos_orders_and_cancelled_or_rejected_preorders_count_nowhere(): void
    {
        [$artist, $variant] = $this->seller('SPF');

        $voided = $this->posSale([[$variant, 5]]);
        app(OrderService::class)->void($voided, 'batal', $this->owner);
        $this->posSale([[$variant, 2]]);

        $cancelled = $this->preorder([[$variant, 1]], paid: 10000);
        app(PreorderService::class)->transitionStatus($cancelled, 'cancelled', 'batal', $this->owner);

        $row = $this->recapRows()[$artist->id];

        $this->assertSame(2, $row['total_units']);
        $this->assertSame('20000.00', $row['total_sales']);
    }

    public function test_demo_mode_sales_and_preorders_never_leak_into_the_live_recap(): void
    {
        [$artist, $variant] = $this->seller('SPG');
        $this->posSale([[$variant, 1]]);

        ModeGate::runAs('demo', function () use ($artist) {
            $event = Event::factory()->create(['status' => 'active']);
            $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $this->cashier->id, 'status' => 'open']);
            $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $this->category->id]);
            $demo = $product->variants()->create(['sku' => 'SPGKYDEM0001', 'sell_price' => 999999, 'cost_price' => 1, 'current_stock' => 100]);
            $this->posSale([[$demo, 1]], $session);
            $this->preorder([[$demo, 1]], paid: 888888, event: $event);
        });
        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'live', 'type' => 'string', 'group' => 'system']);

        $row = $this->recapRows()[$artist->id];

        $this->assertSame('10000.00', $row['total_sales']);
        $this->assertSame(1, $row['total_units']);
    }

    public function test_recap_and_its_export_stay_forbidden_for_cashier_and_inventory(): void
    {
        foreach (['cashier', 'inventory'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum');
            $this->getJson("/api/v1/reports/artist-settlements?event_id={$this->event->id}")->assertForbidden();
            $this->get("/api/v1/reports/artist-settlements/export?event_id={$this->event->id}")->assertForbidden();
        }
    }

    // ===================================================================
    // US3 — Payable, Paid, Outstanding, Record payment
    // ===================================================================

    public function test_a_settlement_row_left_over_from_the_preorder_inclusive_rule_is_reset_and_its_payments_kept(): void
    {
        // Seller yang HANYA punya pre-order: sebelum 040 baris settlement-nya punya
        // total_sales dari pre-order dan sudah ada pembayaran tercatat.
        [$artist, $variant] = $this->seller('SPH');
        $this->preorder([[$variant, 1]], paid: 10000);
        ArtistSettlement::create([
            'event_id' => $this->event->id, 'artist_id' => $artist->id,
            'total_sales' => 10000, 'total_units' => 1, 'deduction' => 0,
            'payable_amount' => 10000, 'paid_amount' => 4000, 'status' => 'partial',
        ]);

        $row = $this->recapRows()[$artist->id];

        $this->assertSame('0.00', $row['total_sales']);
        $this->assertSame(0, $row['total_units']);
        $this->assertSame('0.00', $row['payable_amount']);
        $this->assertSame('4000.00', $row['paid_amount'], 'pembayaran yang sudah tercatat tidak boleh berubah');
        $this->assertSame('0.00', $row['outstanding'], 'Outstanding tidak pernah negatif');
        $this->assertSame('paid', $row['status']);
    }

    public function test_outstanding_is_never_negative_when_paid_exceeds_the_new_pos_only_payable(): void
    {
        [$artist, $variant] = $this->seller('SPI');
        $this->posSale([[$variant, 3]]);   // payable 30000
        ArtistSettlement::create([
            'event_id' => $this->event->id, 'artist_id' => $artist->id,
            'total_sales' => 0, 'total_units' => 0, 'deduction' => 0,
            'payable_amount' => 0, 'paid_amount' => 50000, 'status' => 'paid',
        ]);

        $row = $this->recapRows()[$artist->id];

        $this->assertSame('30000.00', $row['payable_amount']);
        $this->assertSame('50000.00', $row['paid_amount']);
        $this->assertSame('0.00', $row['outstanding']);
        $this->assertSame('paid', $row['status']);
    }

    public function test_recording_a_payment_works_against_the_pos_only_payable_and_moves_the_status(): void
    {
        [$artist, $variant] = $this->seller('SPJ');
        $this->posSale([[$variant, 3]]);
        $this->preorder([[$variant, 5]], paid: 50000);   // tidak menambah apa pun ke Payable

        $settlementId = $this->recapRows()[$artist->id]['id'];

        $this->postJson("/api/v1/reports/artist-settlements/{$settlementId}/payment", ['amount' => 10000])
            ->assertOk()->assertJsonPath('status', 'partial');

        $row = $this->recapRows()[$artist->id];
        $this->assertSame('30000.00', $row['payable_amount']);
        $this->assertSame('10000.00', $row['paid_amount']);
        $this->assertSame('20000.00', $row['outstanding']);

        $this->postJson("/api/v1/reports/artist-settlements/{$settlementId}/payment", ['amount' => 20000])
            ->assertOk()->assertJsonPath('status', 'paid');
        $this->assertSame('0.00', $this->recapRows()[$artist->id]['outstanding']);
    }

    public function test_closing_the_event_stores_the_same_pos_only_numbers_the_recap_shows(): void
    {
        [$artist, $variant] = $this->seller('SPK');
        $this->posSale([[$variant, 2]]);
        $this->preorder([[$variant, 4]], paid: 40000);

        $this->session->update(['status' => 'closed']);
        $this->patchJson("/api/v1/events/{$this->event->id}/status", ['status' => 'closed'])->assertOk();

        $stored = ArtistSettlement::where('event_id', $this->event->id)->where('artist_id', $artist->id)->firstOrFail();

        $this->assertSame('20000.00', (string) $stored->total_sales);
        $this->assertSame(2, (int) $stored->total_units);
        $this->assertSame('20000.00', (string) $stored->payable_amount);
        $this->assertSame($this->recapRows()[$artist->id]['total_sales'], number_format((float) $stored->total_sales, 2, '.', ''));
    }

    // ===================================================================
    // US4 — ekspor
    // ===================================================================

    public function test_recap_export_has_no_preorder_columns_and_matches_the_screen(): void
    {
        [$artist, $variant] = $this->seller('SPL');
        $this->posSale([[$variant, 3]]);
        $this->preorder([[$variant, 2]], paid: 5000);

        $api = $this->recapRows()[$artist->id];
        $summary = $this->exportSheets()['summary'];
        $headings = $summary[0];

        $this->assertSame(
            ['id', 'artist_id', 'artist_name', 'total_sales', 'total_units', 'deduction', 'payable_amount', 'paid_amount', 'outstanding', 'status'],
            $headings
        );

        $row = array_combine($headings, collect($summary)->skip(1)->first(fn ($r) => (string) $r[array_search('artist_id', $headings)] === (string) $artist->id));

        // Excel menyimpan angka sebagai numerik, jadi banding sebagai angka.
        foreach (['total_sales', 'payable_amount', 'paid_amount', 'outstanding'] as $key) {
            $this->assertEqualsWithDelta((float) $api[$key], (float) $row[$key], 0.001, $key);
        }
        $this->assertEquals($api['total_units'], $row['total_units']);
    }

    public function test_recap_export_detail_sheet_adds_up_to_the_summary_sheet(): void
    {
        [, $a] = $this->seller('SPM');
        [, $b] = $this->seller('SPN');
        $this->posSale([[$a, 2]]);
        $this->posSale([[$b, 1], [$a, 1]]);
        $this->preorder([[$a, 3]], paid: 30000);

        $sheets = $this->exportSheets();

        $summaryHeadings = $sheets['summary'][0];
        $summaryTotal = collect($sheets['summary'])->skip(1)
            ->sum(fn ($r) => (float) $r[array_search('total_sales', $summaryHeadings)]);

        $detailHeadings = $sheets['detail'][0];
        $detailTotal = collect($sheets['detail'])->skip(1)
            ->sum(fn ($r) => (float) $r[array_search('line_total', $detailHeadings)]);

        $this->assertEqualsWithDelta($summaryTotal, $detailTotal, 0.001);
        $this->assertEqualsWithDelta(40000.0, $detailTotal, 0.001);
    }
}
