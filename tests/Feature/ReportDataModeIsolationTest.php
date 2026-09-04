<?php

namespace Tests\Feature;

use App\Models\ArtistSettlement;
use App\Models\CashierSession;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Services\OrderService;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 003-seed-demo-live T034 (US3) — LIVE laporan keuangan/stok TIDAK PERNAH
 * berubah nilainya akibat aktivitas mode DEMO, bahkan lewat query
 * hand-rolled `DB::table(...)` di ReportController/SettlementService yang
 * tidak otomatis ikut disaring Eloquent global scope (lihat research.md
 * Decision 3 dan plan.md US3).
 */
class ReportDataModeIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsOwner(): User
    {
        $user = User::factory()->create(['role' => 'owner']);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    /** Membuat 1 order 'completed' senilai $sellPrice di mode $mode, dan mengembalikan [order, variant]. */
    private function createOrderInMode(string $mode, User $cashier, float $sellPrice): array
    {
        return ModeGate::runAs($mode, function () use ($cashier, $sellPrice) {
            $product = Product::factory()->create();
            $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'cost_price' => 1000, 'sell_price' => $sellPrice]);
            app(\App\Services\StockService::class)->applyMovement($variant, 'initial', 10);

            $session = CashierSession::factory()->create(['user_id' => $cashier->id]);

            $order = app(OrderService::class)->create([
                'session_id' => $session->id,
                'items' => [['variant_id' => $variant->id, 'qty' => 1]],
                'payments' => [['method' => 'cash', 'amount' => $sellPrice]],
            ], $cashier);

            return [$order, $variant, $session->event_id];
        });
    }

    public function test_general_sales_report_without_event_filter_never_mixes_demo_into_live(): void
    {
        $owner = $this->actingAsOwner();
        $this->createOrderInMode('live', $owner, 100000);
        $this->createOrderInMode('demo', $owner, 999999); // jauh berbeda supaya kebocoran mudah terdeteksi

        // Mode aktif SAAT REQUEST dibaca dari settings, bukan dari
        // ModeGate::runAs() (yang hanya untuk lingkup pembuatan data di
        // atas) — persis seperti bagaimana permintaan HTTP sungguhan
        // membaca mode.
        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'live', 'type' => 'string', 'group' => 'system']);

        $response = $this->getJson('/api/v1/reports/sales');

        $response->assertOk();
        $this->assertSame('100000.00', $response->json('totals.net_sales'));
        $this->assertCount(1, $response->json('transactions'));
    }

    public function test_general_sales_report_in_demo_mode_never_sees_live_totals(): void
    {
        $owner = $this->actingAsOwner();
        $this->createOrderInMode('live', $owner, 100000);
        $this->createOrderInMode('demo', $owner, 55000);

        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'demo', 'type' => 'string', 'group' => 'system']);

        $response = $this->getJson('/api/v1/reports/sales');

        $response->assertOk();
        $this->assertSame('55000.00', $response->json('totals.net_sales'));
        $this->assertCount(1, $response->json('transactions'));
    }

    public function test_event_profit_report_is_not_contaminated_by_a_demo_order_on_a_different_event(): void
    {
        $owner = $this->actingAsOwner();
        [$liveOrder, , $liveEventId] = $this->createOrderInMode('live', $owner, 200000);
        $this->createOrderInMode('demo', $owner, 777777);

        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'live', 'type' => 'string', 'group' => 'system']);

        $response = $this->getJson("/api/v1/reports/profit?event_id={$liveEventId}");

        $response->assertOk();
        $this->assertSame('200000.00', $response->json('revenue'));
    }

    public function test_artist_settlement_recalculation_is_not_contaminated_by_demo_activity(): void
    {
        $owner = $this->actingAsOwner();
        [$liveOrder, $liveVariant, $liveEventId] = $this->createOrderInMode('live', $owner, 150000);
        $this->createOrderInMode('demo', $owner, 888888);

        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'live', 'type' => 'string', 'group' => 'system']);

        $response = $this->getJson("/api/v1/reports/artist-settlements?event_id={$liveEventId}");

        $response->assertOk();
        $totalSalesSum = collect($response->json('data'))->sum(fn ($row) => (float) $row['total_sales']);
        $this->assertSame(150000.0, $totalSalesSum);

        $settlement = ArtistSettlement::where('event_id', $liveEventId)->first();
        $this->assertNotNull($settlement);
        $this->assertSame('live', $settlement->data_mode);
    }

    public function test_stock_movements_in_demo_never_affect_a_live_variants_current_stock(): void
    {
        $owner = $this->actingAsOwner();
        [, $liveVariant] = $this->createOrderInMode('live', $owner, 50000);

        // current_stock milik variant LIVE ini — dibuat 10, dikurangi 1 oleh
        // penjualan di atas.
        $this->assertSame(9, $liveVariant->fresh()->current_stock);

        // Aktivitas DEMO di variant yang SAMA SEKALI BEDA baris — tidak ada
        // mekanisme yang bisa membuatnya menyentuh baris variant LIVE di
        // atas (setiap mode punya baris product_variants sendiri).
        ModeGate::runAs('demo', function () use ($owner) {
            $product = Product::factory()->create();
            $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'current_stock' => 0]);
            app(\App\Services\StockService::class)->applyMovement($variant, 'initial', 500);
        });

        $this->assertSame(9, $liveVariant->fresh()->current_stock);
    }

    /**
     * research.md Decision 3 — a stale cross-mode reference must never be
     * silently written. `customer_id` is the concrete gap: it goes
     * straight into `Order::create()` with no Eloquent re-fetch (unlike
     * `variant_id`/`session_id`, which already 404 via `findOrFail()`
     * against a HasDataMode model), and the FormRequest's `exists:` rule
     * bypasses Eloquent scopes entirely, so it would accept a DEMO
     * customer's id while LIVE mode is active.
     */
    public function test_order_service_rejects_a_customer_id_belonging_to_a_different_mode(): void
    {
        $owner = $this->actingAsOwner();
        $demoCustomer = ModeGate::runAs('demo', fn () => Customer::factory()->create());

        [, , $liveEventId] = $this->createOrderInMode('live', $owner, 10000);
        $session = CashierSession::factory()->create(['user_id' => $owner->id, 'event_id' => $liveEventId]);
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'sell_price' => 10000]);
        app(\App\Services\StockService::class)->applyMovement($variant, 'initial', 5);

        $this->expectException(ValidationException::class);

        app(OrderService::class)->create([
            'session_id' => $session->id,
            'customer_id' => $demoCustomer->id,
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ], $owner);
    }

    // 009-ui-ux-refinements (US6/T047) — group_by=customer harus ikut
    // pola data_mode ini: hand-rolled DB::table() di sales() cabang
    // 'customer' hanya menyaring via order_items.data_mode yang sudah
    // ada di $base, jadi order DEMO tidak boleh ikut ter-SUM ke baris
    // pelanggan LIVE.
    public function test_sales_report_group_by_customer_never_mixes_demo_into_live(): void
    {
        $owner = $this->actingAsOwner();
        $this->createOrderInMode('live', $owner, 100000);
        $this->createOrderInMode('demo', $owner, 999999);

        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'live', 'type' => 'string', 'group' => 'system']);

        $response = $this->getJson('/api/v1/reports/sales?group_by=customer');

        $response->assertOk();
        $totalAmountSum = collect($response->json('rows'))->sum(fn ($row) => (float) $row['total_amount']);
        $this->assertSame(100000.0, $totalAmountSum);
    }

    public function test_preorder_service_rejects_a_customer_id_belonging_to_a_different_mode(): void
    {
        $owner = $this->actingAsOwner();
        $demoCustomer = ModeGate::runAs('demo', fn () => Customer::factory()->create());
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'sell_price' => 10000]);

        $this->expectException(ValidationException::class);

        app(\App\Services\PreorderService::class)->create([
            'customer_id' => $demoCustomer->id,
            'fulfillment' => 'pickup',
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
        ], $owner);
    }
}
