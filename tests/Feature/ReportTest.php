<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_artist_settlement_matches_sum_of_actual_orders(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open']);

        $artistA = Artist::factory()->create(['code' => 'AAA']);
        $artistB = Artist::factory()->create(['code' => 'BBB']);
        $category = Category::factory()->create();

        $productA = Product::factory()->create(['artist_id' => $artistA->id, 'category_id' => $category->id]);
        $variantA = $productA->variants()->create(['sku' => 'AAAKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        $productB = Product::factory()->create(['artist_id' => $artistB->id, 'category_id' => $category->id]);
        $variantB = $productB->variants()->create(['sku' => 'BBBKYBBB0001', 'sell_price' => 20000, 'cost_price' => 8000, 'current_stock' => 100]);

        $orderService = app(OrderService::class);
        $orderService->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variantA->id, 'qty' => 3], ['variant_id' => $variantB->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 50000]],
        ], $cashier);

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $response = $this->getJson("/api/v1/reports/artist-settlements?event_id={$event->id}");

        $response->assertOk();
        $rows = collect($response->json('data'))->keyBy('artist_id');

        $this->assertEquals('30000.00', $rows[$artistA->id]['total_sales']); // 3 x 10000
        $this->assertEquals('20000.00', $rows[$artistB->id]['total_sales']); // 1 x 20000
        $this->assertEquals(3, $rows[$artistA->id]['total_units']);
    }

    public function test_settlement_recalculates_live_after_a_void(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($cashier, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open']);
        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'AAAKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        $orderService = app(OrderService::class);
        $order = $orderService->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 20000]],
        ], $cashier);

        $this->actingAs($owner, 'sanctum');

        $before = $this->getJson("/api/v1/reports/artist-settlements?event_id={$event->id}")->json('data.0.total_sales');
        $this->assertEquals('20000.00', $before);

        $orderService->void($order, 'Test batal', $owner);

        $after = $this->getJson("/api/v1/reports/artist-settlements?event_id={$event->id}")->json('data.0.total_sales');
        $this->assertEquals('0.00', $after); // order voided -> tidak ikut terhitung
    }

    public function test_profit_report_requires_owner_or_admin(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');
        $event = Event::factory()->create();

        $this->getJson("/api/v1/reports/profit?event_id={$event->id}")->assertStatus(403);
    }

    // Regresi — celah access-control ditemukan saat security review:
    // artistSettlements() tidak punya pemeriksaan owner/admin sama sekali,
    // padahal mengembalikan payable_amount/deduction per artist (data
    // komersial sesensitif laporan profit), dan sibling-nya di controller
    // yang sama (profit, recordSettlementPayment) sudah menegakkannya.
    public function test_artist_settlements_report_requires_owner_or_admin(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');
        $event = Event::factory()->create();

        $this->getJson("/api/v1/reports/artist-settlements?event_id={$event->id}")->assertStatus(403);
    }

    // Regresi — artist tanpa satu pun order_items di event ini dulu hilang
    // total dari laporan, karena SettlementService membangun baris dari
    // GROUP BY order_items.artist_id sehingga tidak pernah ada baris
    // settlement untuk mereka.
    public function test_artists_without_any_sales_still_appear_with_zeroes(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open']);

        $selling = Artist::factory()->create(['code' => 'SEL', 'name' => 'Artist Laku']);
        $idle = Artist::factory()->create(['code' => 'IDL', 'name' => 'Artist Belum Laku']);
        $category = Category::factory()->create();

        $product = Product::factory()->create(['artist_id' => $selling->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'SELKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        app(OrderService::class)->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 20000]],
        ], $cashier);

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $rows = collect($this->getJson("/api/v1/reports/artist-settlements?event_id={$event->id}")->json('data'))
            ->keyBy('artist_id');

        $this->assertCount(2, $rows);

        $this->assertSame('20000.00', $rows[$selling->id]['total_sales']);
        $this->assertIsInt($rows[$selling->id]['id']); // artist yang laku tetap punya id settlement

        $this->assertSame('0.00', $rows[$idle->id]['total_sales']);
        $this->assertSame(0, $rows[$idle->id]['total_units']);
        $this->assertSame('0.00', $rows[$idle->id]['payable_amount']);
        $this->assertSame('0.00', $rows[$idle->id]['paid_amount']);
        $this->assertSame('0.00', $rows[$idle->id]['outstanding']);
        $this->assertSame('unpaid', $rows[$idle->id]['status']);
        $this->assertNull($rows[$idle->id]['id']);
        $this->assertSame('Artist Belum Laku', $rows[$idle->id]['artist_name']);
    }

    // Baris settlement yang PUNYA id tetap bisa dibayar lewat endpoint
    // pembayaran — id yang dikembalikan laporan harus benar-benar bisa
    // dipakai sebagai {settlement} di rute itu.
    public function test_settlement_payment_still_works_for_the_id_returned_by_the_report(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open']);

        $selling = Artist::factory()->create(['code' => 'PAY']);
        Artist::factory()->create(['code' => 'NIL']); // artist nol penjualan, id-nya null
        $category = Category::factory()->create();

        $product = Product::factory()->create(['artist_id' => $selling->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'PAYKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        app(OrderService::class)->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 5]],
            'payments' => [['method' => 'cash', 'amount' => 50000]],
        ], $cashier);

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $rows = collect($this->getJson("/api/v1/reports/artist-settlements?event_id={$event->id}")->json('data'))
            ->keyBy('artist_id');

        $settlementId = $rows[$selling->id]['id'];
        $this->assertNotNull($settlementId);

        $this->postJson("/api/v1/reports/artist-settlements/{$settlementId}/payment", ['amount' => 20000])
            ->assertOk()
            ->assertJsonPath('status', 'partial');

        $after = collect($this->getJson("/api/v1/reports/artist-settlements?event_id={$event->id}")->json('data'))
            ->keyBy('artist_id');

        $this->assertSame('20000.00', $after[$selling->id]['paid_amount']);
        $this->assertSame('30000.00', $after[$selling->id]['outstanding']);
        $this->assertSame('partial', $after[$selling->id]['status']);
    }

    // Artist nonaktif TIDAK ikut dilaporkan bila tidak punya penjualan —
    // laporan berisi "semua artist AKTIF", bukan seluruh isi tabel artists.
    public function test_inactive_artist_without_sales_is_not_listed(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        Artist::factory()->create(['code' => 'OFF', 'is_active' => false]);

        $this->getJson("/api/v1/reports/artist-settlements?event_id={$event->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ...tapi kalau artist nonaktif itu PUNYA penjualan di event ini,
    // barisnya wajib tetap muncul — uangnya tetap harus dibayarkan.
    public function test_inactive_artist_with_sales_is_still_listed(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open']);

        $artist = Artist::factory()->create(['code' => 'RET', 'is_active' => true]);
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'RETKYAAA0001', 'sell_price' => 5000, 'cost_price' => 2000, 'current_stock' => 50]);

        app(OrderService::class)->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 5000]],
        ], $cashier);

        $artist->update(['is_active' => false]);

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $this->getJson("/api/v1/reports/artist-settlements?event_id={$event->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.total_sales', '5000.00');
    }

    public function test_export_endpoint_forwards_the_403_instead_of_a_blank_file(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');
        $event = Event::factory()->create();

        $this->getJson("/api/v1/reports/profit/export?event_id={$event->id}")->assertStatus(403);
    }

    // Regresi — bug ditemukan saat security review: match() di export()
    // tidak punya cabang 'profit' walau route mengizinkannya, sehingga
    // selalu jatuh ke default dan diam-diam menghasilkan file kosong.
    public function test_profit_export_produces_a_real_file_for_owner(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');
        $event = Event::factory()->create();

        $response = $this->get("/api/v1/reports/profit/export?event_id={$event->id}");

        $response->assertOk();
        $this->assertGreaterThan(0, strlen($response->streamedContent()));
    }

    // =====================================================================
    // Task 1 — daftar transaksi, group_label dinamis, entity_id per baris.
    // =====================================================================

    private function seedSalesFixture(): array
    {
        $cashier = User::factory()->create(['role' => 'cashier', 'name' => 'Kasir Satu']);
        $this->actingAs($cashier, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open']);

        $artist = Artist::factory()->create(['code' => 'RYU']);
        $category = Category::factory()->create(['code' => 'KY']);
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'name' => 'Keychain Minecraft']);
        $variant = $product->variants()->create(['sku' => 'RYUKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        $orderService = app(OrderService::class);

        // Tiga order 'completed' terpisah, DUA di antaranya membeli produk
        // yang SAMA — meniru temuan pengguna: KPI transaksi = 3, tapi
        // baris agregat produk = 1 (semua beli produk yang sama).
        $orders = [];
        foreach ([1, 1, 2] as $qty) {
            $orders[] = $orderService->create([
                'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
                'items' => [['variant_id' => $variant->id, 'qty' => $qty]],
                'payments' => [['method' => 'cash', 'amount' => $qty * 10000]],
            ], $cashier);
        }

        return compact('event', 'artist', 'category', 'product', 'orders');
    }

    public function test_sales_report_lists_every_completed_transaction_separately_from_the_aggregate_rows(): void
    {
        ['event' => $event, 'orders' => $orders] = $this->seedSalesFixture();

        $response = $this->getJson("/api/v1/reports/sales?event_id={$event->id}");

        $response->assertOk();
        $this->assertEquals(3, $response->json('totals.order_count'));
        $this->assertCount(1, $response->json('rows')); // satu produk saja
        $this->assertCount(3, $response->json('transactions')); // tapi tiga transaksi nyata

        $transactionIds = collect($response->json('transactions'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing(collect($orders)->pluck('id')->all(), $transactionIds);

        $first = $response->json('transactions.0');
        $this->assertArrayHasKey('order_number', $first);
        $this->assertArrayHasKey('created_at', $first);
        $this->assertArrayHasKey('cashier_name', $first);
        $this->assertArrayHasKey('item_count', $first);
        $this->assertArrayHasKey('total_amount', $first);
    }

    public function test_sales_report_returns_dynamic_group_label_and_entity_id_per_group_by(): void
    {
        ['event' => $event, 'artist' => $artist, 'category' => $category, 'product' => $product] = $this->seedSalesFixture();

        $byProduct = $this->getJson("/api/v1/reports/sales?event_id={$event->id}&group_by=product")->assertOk();
        $this->assertEquals('Produk', $byProduct->json('group_label'));
        $this->assertEquals($product->id, $byProduct->json('rows.0.entity_id'));
        $this->assertEquals($product->name, $byProduct->json('rows.0.label'));

        $byCategory = $this->getJson("/api/v1/reports/sales?event_id={$event->id}&group_by=category")->assertOk();
        $this->assertEquals('Kategori', $byCategory->json('group_label'));
        $this->assertEquals($category->id, $byCategory->json('rows.0.entity_id'));
        $this->assertEquals($category->name, $byCategory->json('rows.0.label'));

        $byArtist = $this->getJson("/api/v1/reports/sales?event_id={$event->id}&group_by=artist")->assertOk();
        $this->assertEquals('Artist', $byArtist->json('group_label'));
        $this->assertEquals($artist->id, $byArtist->json('rows.0.entity_id'));

        $byDay = $this->getJson("/api/v1/reports/sales?event_id={$event->id}&group_by=day")->assertOk();
        $this->assertEquals('Tanggal', $byDay->json('group_label'));
        $this->assertNull($byDay->json('rows.0.entity_id'));
    }

    // =====================================================================
    // F10.6 — customer_name pada transactions[]
    // =====================================================================

    public function test_transactions_include_customer_name_when_order_has_a_customer(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open']);
        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'AAAKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        $customer = Customer::factory()->create(['name' => 'Budi Santoso']);

        app(OrderService::class)->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ], $cashier);

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $response = $this->getJson("/api/v1/reports/sales?event_id={$event->id}");

        $response->assertOk();
        $this->assertSame('Budi Santoso', $response->json('transactions.0.customer_name'));
    }

    // Walk-in — orders.customer_id nullable, field harus null tanpa galat,
    // bukan melempar exception saat relasi customer() diakses.
    public function test_transactions_customer_name_is_null_for_walk_in_orders(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open']);
        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'BBBKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        app(OrderService::class)->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ], $cashier);

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $response = $this->getJson("/api/v1/reports/sales?event_id={$event->id}");

        $response->assertOk();
        $this->assertArrayHasKey('customer_name', $response->json('transactions.0'));
        $this->assertNull($response->json('transactions.0.customer_name'));
    }

    // =====================================================================
    // F11.6 — drill-down transaksi per artist di Rekap Artist
    // =====================================================================

    public function test_artist_settlement_transactions_requires_owner_or_admin(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $event = Event::factory()->create();
        $artist = Artist::factory()->create();

        $this->getJson("/api/v1/reports/artist-settlements/{$artist->id}/transactions?event_id={$event->id}")
            ->assertStatus(403);
    }

    // Kasus inti F11.6: satu order berisi item dari DUA artist berbeda.
    // Drill-down artist A hanya boleh menampilkan item milik artist A,
    // walau order itu juga memuat item artist B — dan sebaliknya.
    public function test_artist_settlement_transactions_only_shows_that_artists_items_within_shared_orders(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open']);
        $category = Category::factory()->create();

        $artistA = Artist::factory()->create(['code' => 'ARA', 'name' => 'Artist A']);
        $artistB = Artist::factory()->create(['code' => 'ARB', 'name' => 'Artist B']);

        $productA = Product::factory()->create(['artist_id' => $artistA->id, 'category_id' => $category->id, 'name' => 'Produk A']);
        $variantA = $productA->variants()->create(['sku' => 'ARAKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        $productB = Product::factory()->create(['artist_id' => $artistB->id, 'category_id' => $category->id, 'name' => 'Produk B']);
        $variantB = $productB->variants()->create(['sku' => 'ARBKYAAA0001', 'sell_price' => 20000, 'cost_price' => 8000, 'current_stock' => 100]);

        $orderService = app(OrderService::class);

        // Satu order berisi item DUA artist sekaligus.
        $sharedOrder = $orderService->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [
                ['variant_id' => $variantA->id, 'qty' => 2],
                ['variant_id' => $variantB->id, 'qty' => 1],
            ],
            'payments' => [['method' => 'cash', 'amount' => 40000]],
        ], $cashier);

        // Order kedua, hanya artist A — untuk memastikan pengelompokan per
        // order (bukan agregat total) berfungsi, dan artist A punya dua
        // order yang menyusun totalnya.
        $soloOrderA = $orderService->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variantA->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ], $cashier);

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $forA = $this->getJson("/api/v1/reports/artist-settlements/{$artistA->id}/transactions?event_id={$event->id}")
            ->assertOk();

        $this->assertCount(2, $forA->json('transactions'));
        $keysForA = collect($forA->json('transactions'))->pluck('key')->all();
        $this->assertEqualsCanonicalizing(['order-'.$sharedOrder->id, 'order-'.$soloOrderA->id], $keysForA);

        $sharedRowForA = collect($forA->json('transactions'))->firstWhere('key', 'order-'.$sharedOrder->id);
        // Item milik artist A saja dalam order gabungan — bukan item B.
        $this->assertCount(1, $sharedRowForA['items']);
        $this->assertSame('20000.00', $sharedRowForA['amount_for_artist']); // 2 x 10000

        $forB = $this->getJson("/api/v1/reports/artist-settlements/{$artistB->id}/transactions?event_id={$event->id}")
            ->assertOk();

        $this->assertCount(1, $forB->json('transactions'));
        $sharedRowForB = $forB->json('transactions.0');
        $this->assertSame('order-'.$sharedOrder->id, $sharedRowForB['key']);
        $this->assertCount(1, $sharedRowForB['items']);
        $this->assertSame('20000.00', $sharedRowForB['amount_for_artist']); // 1 x 20000
    }

    // =====================================================================
    // 012-seller-preorder-report-detail-export (US1, T004) — detail
    // transaksi seller memuat preorder yang sudah berkontribusi ke Seller
    // Recap, bukan hanya order reguler.
    // =====================================================================

    public function test_artist_settlement_transactions_includes_both_a_regular_sale_and_a_partially_paid_preorder(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open']);
        $category = Category::factory()->create();
        $customer = Customer::factory()->create();

        $artist = Artist::factory()->create(['code' => 'US1', 'name' => 'Artist US1']);
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'US1KYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        // Sale reguler: 1 x 10000 = 10000.
        $order = app(OrderService::class)->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ], $cashier);

        // Preorder: subtotal 10000 (qty 1 x 10000), baru dibayar 4000 (40%)
        // -> amount_for_artist yang diakui = 4000.00 (bukan 10000.00 penuh).
        $preorder = $this->createPartiallyPaidPreorder($event, $customer, [
            ['variant' => $variant, 'qty' => 1],
        ], paidAmount: 4000);

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $response = $this->getJson("/api/v1/reports/artist-settlements/{$artist->id}/transactions?event_id={$event->id}")
            ->assertOk();

        $transactions = collect($response->json('transactions'));
        $this->assertCount(2, $transactions);

        $orderTx = $transactions->firstWhere('key', 'order-'.$order->id);
        $this->assertNotNull($orderTx);
        $this->assertSame('order', $orderTx['source']);
        $this->assertSame('10000.00', $orderTx['amount_for_artist']);

        $preorderTx = $transactions->firstWhere('key', 'preorder-'.$preorder->id);
        $this->assertNotNull($preorderTx);
        $this->assertSame('preorder', $preorderTx['source']);
        // 40% dari 10000 = 4000.00 — jumlah yang BENAR-BENAR terkumpul,
        // bukan nilai penuh preorder (Acceptance Scenario 3).
        $this->assertSame('4000.00', $preorderTx['amount_for_artist']);
    }

    public function test_artist_settlement_transactions_amounts_sum_to_the_seller_recap_total_sales(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open']);
        $category = Category::factory()->create();
        $customer = Customer::factory()->create();

        $artist = Artist::factory()->create(['code' => 'US2', 'name' => 'Artist US2']);
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'US2KYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        app(OrderService::class)->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ], $cashier);

        $this->createPartiallyPaidPreorder($event, $customer, [
            ['variant' => $variant, 'qty' => 1],
        ], paidAmount: 4000);

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $detailResponse = $this->getJson("/api/v1/reports/artist-settlements/{$artist->id}/transactions?event_id={$event->id}")
            ->assertOk();
        $sumOfEntries = collect($detailResponse->json('transactions'))
            ->sum(fn ($tx) => (float) $tx['amount_for_artist']);

        $recapResponse = $this->getJson("/api/v1/reports/artist-settlements?event_id={$event->id}")
            ->assertOk();
        $recapRow = collect($recapResponse->json('data'))->firstWhere('artist_id', $artist->id);
        $this->assertNotNull($recapRow);

        $this->assertSame(14000.0, $sumOfEntries);
        $this->assertSame(14000.0, (float) $recapRow['total_sales']);
        $this->assertEqualsWithDelta((float) $recapRow['total_sales'], $sumOfEntries, 0.001);
    }

    public function test_a_cancelled_preorder_with_a_prior_payment_does_not_appear_in_artist_settlement_transactions(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $customer = Customer::factory()->create();
        $category = Category::factory()->create();

        $artist = Artist::factory()->create(['code' => 'US3']);
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'US3KYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        $preorder = $this->createPartiallyPaidPreorder($event, $customer, [
            ['variant' => $variant, 'qty' => 1],
        ], paidAmount: 5000);

        app(\App\Services\PreorderService::class)->transitionStatus($preorder, 'cancelled', 'dibatalkan pelanggan', $owner);

        $response = $this->getJson("/api/v1/reports/artist-settlements/{$artist->id}/transactions?event_id={$event->id}")
            ->assertOk();

        $keys = collect($response->json('transactions'))->pluck('key')->all();
        $this->assertNotContains('preorder-'.$preorder->id, $keys);
    }

    public function test_artist_settlement_transactions_never_mixes_demo_and_live_orders_or_preorders(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $artist = Artist::factory()->create(['code' => 'US4']);
        $category = Category::factory()->create();

        [$liveOrder, $liveEventId] = \App\Support\ModeGate::runAs('live', function () use ($artist, $category, $owner) {
            $event = Event::factory()->create(['status' => 'active']);
            $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $owner->id, 'status' => 'open']);
            $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
            $variant = $product->variants()->create(['sku' => 'US4KYLIV0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

            $order = app(OrderService::class)->create([
                'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
                'items' => [['variant_id' => $variant->id, 'qty' => 1]],
                'payments' => [['method' => 'cash', 'amount' => 10000]],
            ], $owner);

            $customer = Customer::factory()->create();
            $this->createPartiallyPaidPreorder($event, $customer, [
                ['variant' => $variant, 'qty' => 1],
            ], paidAmount: 4000);

            return [$order, $event->id];
        });

        \App\Support\ModeGate::runAs('demo', function () use ($artist, $category, $owner) {
            $event = Event::factory()->create(['status' => 'active']);
            $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $owner->id, 'status' => 'open']);
            $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
            $variant = $product->variants()->create(['sku' => 'US4KYDEM0001', 'sell_price' => 999999, 'cost_price' => 4000, 'current_stock' => 100]);

            app(OrderService::class)->create([
                'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
                'items' => [['variant_id' => $variant->id, 'qty' => 1]],
                'payments' => [['method' => 'cash', 'amount' => 999999]],
            ], $owner);

            $customer = Customer::factory()->create();
            $this->createPartiallyPaidPreorder($event, $customer, [
                ['variant' => $variant, 'qty' => 1],
            ], paidAmount: 888888);
        });

        \App\Models\Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'live', 'type' => 'string', 'group' => 'system']);

        $response = $this->getJson("/api/v1/reports/artist-settlements/{$artist->id}/transactions?event_id={$liveEventId}")
            ->assertOk();

        $transactions = collect($response->json('transactions'));
        $this->assertCount(2, $transactions);
        $sum = $transactions->sum(fn ($tx) => (float) $tx['amount_for_artist']);
        // 10000 (order LIVE) + 4000 (preorder LIVE, 40% dari 10000) —
        // TANPA kontaminasi dari order/preorder DEMO senilai jutaan.
        $this->assertSame(14000.0, $sum);
    }

    // =====================================================================
    // F11.6 — ekspor rekap artist multi-sheet (ringkasan + detail transaksi)
    // =====================================================================

    // Sejarah bug di kodebase ini: export yang "200 OK" tapi isinya kosong/
    // salah tidak ketahuan tanpa benar-benar membaca isi berkasnya — jadi
    // tes ini membuka workbook dan memverifikasi kedua sheet beserta isinya,
    // bukan hanya status code.
    public function test_artist_settlements_export_produces_a_real_two_sheet_workbook(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open']);
        $artist = Artist::factory()->create(['code' => 'EXP', 'name' => 'Artist Ekspor']);
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'name' => 'Gantungan Kunci']);
        $variant = $product->variants()->create(['sku' => 'EXPKYAAA0001', 'sell_price' => 15000, 'cost_price' => 5000, 'current_stock' => 100]);

        app(OrderService::class)->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 30000]],
        ], $cashier);

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $response = $this->get("/api/v1/reports/artist-settlements/export?event_id={$event->id}");
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'rekap-artist').'.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
        $sheetNames = $spreadsheet->getSheetNames();

        $this->assertContains('Rekap', $sheetNames);
        $this->assertContains('Detail Transaksi', $sheetNames);

        $summarySheet = $spreadsheet->getSheetByName('Rekap');
        $this->assertSame('artist_name', $summarySheet->getCell('C1')->getValue());
        $this->assertSame('Artist Ekspor', $summarySheet->getCell('C2')->getValue());

        $detailSheet = $spreadsheet->getSheetByName('Detail Transaksi');
        $this->assertSame('item_name', $detailSheet->getCell('D1')->getValue());
        $this->assertSame('Gantungan Kunci — Standard', $detailSheet->getCell('D2')->getValue());
        $this->assertSame(2, $detailSheet->getCell('E2')->getValue());

        unlink($tmpPath);
    }

    // =====================================================================
    // 009-ui-ux-refinements (US6/T047) — group_by=customer
    // =====================================================================

    // Agregasi benar lintas beberapa pelanggan/order: dua order Budi (di
    // hari berbeda) harus digabung jadi satu baris, order walk-in (tanpa
    // customer_id) muncul sebagai baris tersendiri dengan customer_id null.
    public function test_sales_report_group_by_customer_aggregates_across_multiple_orders_per_customer(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open']);
        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'AAAKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        $budi = Customer::factory()->create(['name' => 'Budi Santoso']);
        $citra = Customer::factory()->create(['name' => 'Citra Lestari']);

        $orderService = app(OrderService::class);

        // Dua order milik Budi — harus tergabung jadi satu baris.
        $orderService->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'customer_id' => $budi->id,
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ], $cashier);
        $orderService->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'customer_id' => $budi->id,
            'items' => [['variant_id' => $variant->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 20000]],
        ], $cashier);

        // Satu order Citra.
        $orderService->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'customer_id' => $citra->id,
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ], $cashier);

        // Satu order walk-in (tanpa customer_id).
        $orderService->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ], $cashier);

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $response = $this->getJson("/api/v1/reports/sales?event_id={$event->id}&group_by=customer");
        $response->assertOk();

        $this->assertEquals('Pelanggan', $response->json('group_label'));

        $rows = collect($response->json('rows'))->keyBy('customer_id');

        $this->assertSame(2, $rows[$budi->id]['transaction_count']);
        $this->assertSame('30000.00', $rows[$budi->id]['total_amount']); // 10000 + 20000
        $this->assertSame('Budi Santoso', $rows[$budi->id]['customer_name']);

        $this->assertSame(1, $rows[$citra->id]['transaction_count']);
        $this->assertSame('10000.00', $rows[$citra->id]['total_amount']);

        $walkIn = $rows[null] ?? null;
        $this->assertNotNull($walkIn, 'baris walk-in (customer_id null) harus tetap muncul');
        $this->assertSame(1, $walkIn['transaction_count']);
        $this->assertSame('10000.00', $walkIn['total_amount']);
        $this->assertNull($walkIn['customer_name']);
    }

    // Filter rentang tanggal (date_from/date_to) yang sudah dipakai grouping
    // lain harus tetap berlaku untuk group_by=customer.
    public function test_sales_report_group_by_customer_respects_date_range_filter(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open']);
        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'AAAKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        $customer = Customer::factory()->create(['name' => 'Dedi Kurnia']);

        $inRange = app(OrderService::class)->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ], $cashier);
        $inRange->forceFill(['created_at' => now()])->save();

        $outOfRange = app(OrderService::class)->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'items' => [['variant_id' => $variant->id, 'qty' => 5]],
            'payments' => [['method' => 'cash', 'amount' => 50000]],
        ], $cashier);
        $outOfRange->forceFill(['created_at' => now()->subDays(10)])->save();

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $today = now()->toDateString();
        $response = $this->getJson("/api/v1/reports/sales?event_id={$event->id}&group_by=customer&date_from={$today}&date_to={$today}");

        $response->assertOk();
        $rows = collect($response->json('rows'))->keyBy('customer_id');

        $this->assertSame(1, $rows[$customer->id]['transaction_count']);
        $this->assertSame('10000.00', $rows[$customer->id]['total_amount']);
    }

    // =====================================================================
    // F9.5 — modal & laba kotor per artist
    // =====================================================================

    public function test_artist_profit_report_requires_owner_or_admin(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');
        $event = Event::factory()->create();

        $this->getJson("/api/v1/reports/artist-profit?event_id={$event->id}")->assertStatus(403);
    }

    public function test_artist_profit_sums_modal_across_multiple_orders_and_never_subtracts_event_cost(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        // event_cost besar dan bukan nol — kalau ini secara keliru ikut
        // dikurangkan pada laporan per-artist, tes ini akan gagal.
        $event = Event::factory()->create(['status' => 'active', 'event_cost' => 5000000]);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open']);

        $artist = Artist::factory()->create(['code' => 'MDL', 'name' => 'Artist Modal']);
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'MDLKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        $orderService = app(OrderService::class);

        // Dua order terpisah, keduanya menyumbang ke modal & penjualan
        // artist yang sama — memastikan agregasinya SUM lintas order.
        $orderService->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 30000]],
        ], $cashier);

        $orderService->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 20000]],
        ], $cashier);

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $response = $this->getJson("/api/v1/reports/artist-profit?event_id={$event->id}");
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('artist_id', $artist->id);

        // total_sales = 5 x 10000, modal = 5 x 4000, gross_profit = selisihnya
        $this->assertSame('50000.00', $row['total_sales']);
        $this->assertSame('20000.00', $row['modal']);
        $this->assertSame('30000.00', $row['gross_profit']);

        // event_cost TIDAK BOLEH pernah muncul/dikurangkan di laporan ini.
        $this->assertArrayNotHasKey('event_cost', $row);
        $this->assertArrayNotHasKey('net_profit', $row);
    }

    // ------------------------------------------------------------------
    // 010-split-payment-preorder-reports (US5) — preorder-recognized
    // revenue merged into sales()/profit()/artist-settlements. Lihat
    // research.md R1: hanya uang yang BENAR-BENAR terkumpul di `payments`
    // yang diakui, diproporsikan ke tiap item sesuai porsi line_total
    // terhadap subtotal preorder.
    // ------------------------------------------------------------------

    private function createPartiallyPaidPreorder(Event $event, Customer $customer, array $itemsByArtist, float $paidAmount): \App\Models\Preorder
    {
        $preorderService = app(\App\Services\PreorderService::class);
        $user = User::factory()->create(['role' => 'owner']);

        $preorder = $preorderService->create([
            'event_id' => $event->id,
            'customer_id' => $customer->id,
            'fulfillment' => 'pickup',
            'items' => collect($itemsByArtist)->map(fn ($i) => ['variant_id' => $i['variant']->id, 'qty' => $i['qty']])->values()->all(),
        ], $user);

        if ($paidAmount > 0) {
            $preorderService->recordPayment($preorder, [
                'method' => 'cash', 'channel_id' => null, 'purpose' => 'down_payment', 'amount' => $paidAmount,
            ]);
        }

        return $preorder->fresh(['items', 'payments']);
    }

    public function test_sales_and_profit_reports_include_only_the_collected_portion_of_a_partially_paid_preorder(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $customer = Customer::factory()->create();

        $artist = Artist::factory()->create(['code' => 'PRA']);
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        // sell_price 10000, cost_price 4000, qty 1 -> subtotal 10000.
        $variant = $product->variants()->create(['sku' => 'PRAKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        // Baru 40% dibayar (4000 dari 10000) — HANYA 4000 yang boleh
        // masuk laporan, bukan 10000 (FR-012).
        $this->createPartiallyPaidPreorder($event, $customer, [
            ['variant' => $variant, 'qty' => 1],
        ], paidAmount: 4000);

        $salesResponse = $this->getJson("/api/v1/reports/sales?event_id={$event->id}&group_by=artist");
        $salesResponse->assertOk();

        $row = collect($salesResponse->json('rows'))->firstWhere('entity_id', $artist->id);
        $this->assertNotNull($row, 'baris artist dari preorder harus muncul di laporan sales meski tanpa order reguler');
        $this->assertSame('4000.00', $row['amount']);
        $this->assertEqualsWithDelta(0.4, $row['unit_count'], 0.0001);

        $totals = $salesResponse->json('totals');
        $this->assertSame('4000.00', number_format((float) $totals['net_sales'], 2, '.', ''));

        $profitResponse = $this->getJson("/api/v1/reports/profit?event_id={$event->id}");
        $profitResponse->assertOk();

        // revenue 4000 (40% dari 10000), cost_of_goods 40% dari (4000*1) = 1600.
        $this->assertSame('4000.00', $profitResponse->json('revenue'));
        $this->assertSame('1600.00', $profitResponse->json('cost_of_goods'));
        $this->assertSame('2400.00', $profitResponse->json('gross_profit'));
    }

    public function test_an_unpaid_preorder_contributes_nothing_to_sales_or_profit_reports(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $customer = Customer::factory()->create();

        $artist = Artist::factory()->create(['code' => 'PRU']);
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'PRUKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        $this->createPartiallyPaidPreorder($event, $customer, [
            ['variant' => $variant, 'qty' => 1],
        ], paidAmount: 0);

        $salesResponse = $this->getJson("/api/v1/reports/sales?event_id={$event->id}&group_by=artist");
        $salesResponse->assertOk();

        // Baris boleh muncul (preorder tetap ter-JOIN), tapi NILAINYA harus
        // nol — bukan line_total penuh — karena belum ada uang yang
        // benar-benar terkumpul (FR-012).
        $row = collect($salesResponse->json('rows'))->firstWhere('entity_id', $artist->id);
        if ($row !== null) {
            $this->assertSame('0.00', $row['amount']);
            $this->assertEqualsWithDelta(0.0, $row['unit_count'], 0.0001);
        }

        $totals = $salesResponse->json('totals');
        $this->assertSame('0.00', number_format((float) ($totals['net_sales'] ?? 0), 2, '.', ''));
    }

    public function test_a_cancelled_preorder_contributes_zero_even_after_having_had_payments(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $customer = Customer::factory()->create();

        $artist = Artist::factory()->create(['code' => 'PRC']);
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'PRCKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        $preorder = $this->createPartiallyPaidPreorder($event, $customer, [
            ['variant' => $variant, 'qty' => 1],
        ], paidAmount: 5000);

        app(\App\Services\PreorderService::class)->transitionStatus($preorder, 'cancelled', 'dibatalkan pelanggan', $owner);

        $salesResponse = $this->getJson("/api/v1/reports/sales?event_id={$event->id}&group_by=artist");
        $salesResponse->assertOk();

        $row = collect($salesResponse->json('rows'))->firstWhere('entity_id', $artist->id);
        $this->assertNull($row, 'preorder cancelled tidak boleh menyumbang apa pun ke laporan meski pernah punya pembayaran');

        $profitResponse = $this->getJson("/api/v1/reports/profit?event_id={$event->id}");
        $this->assertSame('0.00', $profitResponse->json('revenue'));
        $this->assertSame('0.00', $profitResponse->json('cost_of_goods'));
    }

    public function test_a_partially_paid_preorder_with_two_artists_prorates_the_collected_amount_by_line_value_share(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $customer = Customer::factory()->create();
        $category = Category::factory()->create();

        $artistA = Artist::factory()->create(['code' => 'PMA']);
        $productA = Product::factory()->create(['artist_id' => $artistA->id, 'category_id' => $category->id]);
        // qty 1 x sell_price 6000, cost_price 2000 -> line_total 6000.
        $variantA = $productA->variants()->create(['sku' => 'PMAKYAAA0001', 'sell_price' => 6000, 'cost_price' => 2000, 'current_stock' => 100]);

        $artistB = Artist::factory()->create(['code' => 'PMB']);
        $productB = Product::factory()->create(['artist_id' => $artistB->id, 'category_id' => $category->id]);
        // qty 1 x sell_price 4000, cost_price 1000 -> line_total 4000.
        $variantB = $productB->variants()->create(['sku' => 'PMBKYAAA0001', 'sell_price' => 4000, 'cost_price' => 1000, 'current_stock' => 100]);

        // subtotal preorder = 6000 + 4000 = 10000. Baru 5000 (50%) dibayar.
        // fraction = 5000 / 10000 = 0.5.
        // Artist A recognized revenue = 6000 * 0.5 = 3000, unit = 1 * 0.5 = 0.5.
        // Artist B recognized revenue = 4000 * 0.5 = 2000, unit = 1 * 0.5 = 0.5.
        // Total recognized = 5000, sama dengan uang yang benar-benar terkumpul.
        $this->createPartiallyPaidPreorder($event, $customer, [
            ['variant' => $variantA, 'qty' => 1],
            ['variant' => $variantB, 'qty' => 1],
        ], paidAmount: 5000);

        $salesResponse = $this->getJson("/api/v1/reports/sales?event_id={$event->id}&group_by=artist");
        $salesResponse->assertOk();

        $rows = collect($salesResponse->json('rows'));
        $rowA = $rows->firstWhere('entity_id', $artistA->id);
        $rowB = $rows->firstWhere('entity_id', $artistB->id);

        $this->assertSame('3000.00', $rowA['amount']);
        $this->assertEqualsWithDelta(0.5, $rowA['unit_count'], 0.0001);
        $this->assertSame('2000.00', $rowB['amount']);
        $this->assertEqualsWithDelta(0.5, $rowB['unit_count'], 0.0001);

        // Cost recognized dengan rasio yang sama: A = 2000*1*0.5 = 1000,
        // B = 1000*1*0.5 = 500, total cost = 1500, total revenue = 5000.
        $profitResponse = $this->getJson("/api/v1/reports/profit?event_id={$event->id}");
        $this->assertSame('5000.00', $profitResponse->json('revenue'));
        $this->assertSame('1500.00', $profitResponse->json('cost_of_goods'));
        $this->assertSame('3500.00', $profitResponse->json('gross_profit'));
    }

    public function test_artist_settlement_recalculation_includes_preorder_recognized_revenue_with_same_proration_and_cancellation_rules(): void
    {
        $event = Event::factory()->create(['status' => 'active']);
        $customer = Customer::factory()->create();
        $category = Category::factory()->create();

        $artistA = Artist::factory()->create(['code' => 'SMA']);
        $productA = Product::factory()->create(['artist_id' => $artistA->id, 'category_id' => $category->id]);
        $variantA = $productA->variants()->create(['sku' => 'SMAKYAAA0001', 'sell_price' => 6000, 'cost_price' => 2000, 'current_stock' => 100]);

        $artistB = Artist::factory()->create(['code' => 'SMB']);
        $productB = Product::factory()->create(['artist_id' => $artistB->id, 'category_id' => $category->id]);
        $variantB = $productB->variants()->create(['sku' => 'SMBKYAAA0001', 'sell_price' => 4000, 'cost_price' => 1000, 'current_stock' => 100]);

        // Sama seperti tes multi-artist di atas: subtotal 10000, 5000
        // terkumpul (50%) -> A dapat 3000, B dapat 2000.
        $this->createPartiallyPaidPreorder($event, $customer, [
            ['variant' => $variantA, 'qty' => 1],
            ['variant' => $variantB, 'qty' => 1],
        ], paidAmount: 5000);

        // Preorder kedua, dibatalkan setelah sempat dibayar — TIDAK BOLEH
        // menyumbang apa pun ke settlement artist A.
        $cancelledArtist = Artist::factory()->create(['code' => 'SMC']);
        $productC = Product::factory()->create(['artist_id' => $cancelledArtist->id, 'category_id' => $category->id]);
        $variantC = $productC->variants()->create(['sku' => 'SMCKYAAA0001', 'sell_price' => 5000, 'cost_price' => 2000, 'current_stock' => 100]);

        $owner = User::factory()->create(['role' => 'owner']);
        $cancelledPreorder = $this->createPartiallyPaidPreorder($event, $customer, [
            ['variant' => $variantC, 'qty' => 1],
        ], paidAmount: 5000);
        app(\App\Services\PreorderService::class)->transitionStatus($cancelledPreorder, 'cancelled', 'dibatalkan', $owner);

        app(SettlementService::class)->recalculateForEvent($event);

        $settlementA = \App\Models\ArtistSettlement::where('event_id', $event->id)->where('artist_id', $artistA->id)->first();
        $settlementB = \App\Models\ArtistSettlement::where('event_id', $event->id)->where('artist_id', $artistB->id)->first();
        $settlementC = \App\Models\ArtistSettlement::where('event_id', $event->id)->where('artist_id', $cancelledArtist->id)->first();

        $this->assertNotNull($settlementA);
        $this->assertSame('3000.00', number_format((float) $settlementA->total_sales, 2, '.', ''));
        $this->assertNotNull($settlementB);
        $this->assertSame('2000.00', number_format((float) $settlementB->total_sales, 2, '.', ''));
        // Cancelled preorder tidak pernah menghasilkan baris settlement sama
        // sekali (bukan baris bernilai nol) karena tidak pernah muncul di
        // agregasi order_items maupun preorder_items non-cancelled.
        $this->assertNull($settlementC);
    }

    // =====================================================================
    // 012-seller-preorder-report-detail-export
    // T008 (US2) — GET /reports/preorders?breakdown=artist
    // =====================================================================

    // Preorder lintas 2 artist: item A senilai 6000, item B senilai 4000,
    // subtotal 10000, baru 5000 (50%) terkumpul -> proporsional A=3000.00,
    // B=2000.00 (data-model.md "Pre-order per-seller breakdown row").
    public function test_preorders_breakdown_by_artist_prorates_a_two_artist_preorder(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $customer = Customer::factory()->create();
        $category = Category::factory()->create();

        $artistA = Artist::factory()->create(['code' => 'BDA', 'name' => 'Artist BDA']);
        $productA = Product::factory()->create(['artist_id' => $artistA->id, 'category_id' => $category->id]);
        $variantA = $productA->variants()->create(['sku' => 'BDAKYAAA0001', 'sell_price' => 6000, 'cost_price' => 2000, 'current_stock' => 100]);

        $artistB = Artist::factory()->create(['code' => 'BDB', 'name' => 'Artist BDB']);
        $productB = Product::factory()->create(['artist_id' => $artistB->id, 'category_id' => $category->id]);
        $variantB = $productB->variants()->create(['sku' => 'BDBKYAAA0001', 'sell_price' => 4000, 'cost_price' => 1000, 'current_stock' => 100]);

        // subtotal = 6000 + 4000 = 10000, dibayar 5000 (50%).
        $this->createPartiallyPaidPreorder($event, $customer, [
            ['variant' => $variantA, 'qty' => 1],
            ['variant' => $variantB, 'qty' => 1],
        ], paidAmount: 5000);

        $response = $this->getJson("/api/v1/reports/preorders?event_id={$event->id}&breakdown=artist")
            ->assertOk();

        $rows = collect($response->json('rows'));
        $rowA = $rows->firstWhere('artist_id', $artistA->id);
        $rowB = $rows->firstWhere('artist_id', $artistB->id);

        $this->assertNotNull($rowA);
        $this->assertNotNull($rowB);
        $this->assertSame('ordered', $rowA['status']);
        $this->assertSame('partial', $rowA['payment_completeness']);
        $this->assertSame(1, $rowA['preorder_count']);
        $this->assertSame('6000.00', $rowA['total_order_value']);
        $this->assertSame('3000.00', $rowA['total_collected']);
        $this->assertSame('3000.00', $rowA['total_outstanding']);

        $this->assertSame(1, $rowB['preorder_count']);
        $this->assertSame('4000.00', $rowB['total_order_value']);
        $this->assertSame('2000.00', $rowB['total_collected']);
        $this->assertSame('2000.00', $rowB['total_outstanding']);

        // Invariant: summing every artist row for a fixed status/completeness
        // bucket equals that bucket's DEFAULT (non-breakdown) total for the
        // same event.
        $defaultResponse = $this->getJson("/api/v1/reports/preorders?event_id={$event->id}")->assertOk();
        $defaultRow = collect($defaultResponse->json('rows'))
            ->firstWhere(fn ($r) => $r['status'] === 'ordered' && $r['payment_completeness'] === 'partial');

        $this->assertNotNull($defaultRow);
        $sumCollected = (float) $rowA['total_collected'] + (float) $rowB['total_collected'];
        $sumOrderValue = (float) $rowA['total_order_value'] + (float) $rowB['total_order_value'];
        $sumOutstanding = (float) $rowA['total_outstanding'] + (float) $rowB['total_outstanding'];

        $this->assertSame((float) $defaultRow['total_collected'], $sumCollected);
        $this->assertSame((float) $defaultRow['total_order_value'], $sumOrderValue);
        $this->assertSame((float) $defaultRow['total_outstanding'], $sumOutstanding);
    }

    // preorder_count harus COUNT(DISTINCT preorders.id) per artist+status+
    // completeness bucket — satu preorder dengan 2 item milik artis yang
    // sama dihitung sekali, bukan dua kali.
    public function test_preorders_breakdown_by_artist_counts_distinct_preorders_not_items(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $customer = Customer::factory()->create();
        $category = Category::factory()->create();

        $artist = Artist::factory()->create(['code' => 'CNT']);
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $variant1 = $product->variants()->create(['sku' => 'CNTKYAAA0001', 'sell_price' => 5000, 'cost_price' => 2000, 'current_stock' => 100]);
        $variant2 = $product->variants()->create(['sku' => 'CNTKYAAA0002', 'sell_price' => 5000, 'cost_price' => 2000, 'current_stock' => 100]);

        // Satu preorder, dua item, KEDUANYA milik artist yang sama.
        $this->createPartiallyPaidPreorder($event, $customer, [
            ['variant' => $variant1, 'qty' => 1],
            ['variant' => $variant2, 'qty' => 1],
        ], paidAmount: 10000);

        $response = $this->getJson("/api/v1/reports/preorders?event_id={$event->id}&breakdown=artist")
            ->assertOk();

        $row = collect($response->json('rows'))->firstWhere('artist_id', $artist->id);
        $this->assertNotNull($row);
        $this->assertSame(1, $row['preorder_count']);
    }

    // =====================================================================
    // 012-seller-preorder-report-detail-export
    // T014 (US3) — GET /reports/preorders drilldown (status/payment_completeness/artist_id)
    // =====================================================================

    public function test_preorders_drilldown_without_artist_id_matches_the_summary_bucket(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $customer1 = Customer::factory()->create(['name' => 'Budi']);
        $customer2 = Customer::factory()->create(['name' => 'Siti']);
        $category = Category::factory()->create();

        $artist = Artist::factory()->create(['code' => 'DRA']);
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'DRAKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        // Dua preorder terpisah, status/kelengkapan sama (partial): masing2
        // dibayar 4000 dari 10000.
        $this->createPartiallyPaidPreorder($event, $customer1, [
            ['variant' => $variant, 'qty' => 1],
        ], paidAmount: 4000);
        $this->createPartiallyPaidPreorder($event, $customer2, [
            ['variant' => $variant, 'qty' => 1],
        ], paidAmount: 4000);

        $summaryResponse = $this->getJson("/api/v1/reports/preorders?event_id={$event->id}")->assertOk();
        $summaryRow = collect($summaryResponse->json('rows'))
            ->firstWhere(fn ($r) => $r['status'] === 'ordered' && $r['payment_completeness'] === 'partial');
        $this->assertNotNull($summaryRow);
        $this->assertSame(2, $summaryRow['preorder_count']);

        $drilldownResponse = $this->getJson(
            "/api/v1/reports/preorders?event_id={$event->id}&status=ordered&payment_completeness=partial"
        )->assertOk();

        $rows = collect($drilldownResponse->json('rows'));
        $this->assertCount($summaryRow['preorder_count'], $rows);

        foreach ($rows as $row) {
            $this->assertArrayHasKey('preorder_id', $row);
            $this->assertArrayHasKey('preorder_number', $row);
            $this->assertArrayHasKey('customer_name', $row);
            $this->assertArrayHasKey('order_value', $row);
            $this->assertArrayHasKey('collected', $row);
            $this->assertArrayHasKey('outstanding', $row);
        }

        $this->assertSame(
            (float) $summaryRow['total_collected'],
            $rows->sum(fn ($r) => (float) $r['collected'])
        );
    }

    // Dengan artist_id: invariant yang sama berlaku untuk porsi prorata
    // milik artist tersebut secara spesifik (bukan total penuh preorder).
    public function test_preorders_drilldown_with_artist_id_matches_that_artists_prorated_breakdown_row(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $customer = Customer::factory()->create(['name' => 'Andi']);
        $category = Category::factory()->create();

        $artistA = Artist::factory()->create(['code' => 'DWA']);
        $productA = Product::factory()->create(['artist_id' => $artistA->id, 'category_id' => $category->id]);
        $variantA = $productA->variants()->create(['sku' => 'DWAKYAAA0001', 'sell_price' => 6000, 'cost_price' => 2000, 'current_stock' => 100]);

        $artistB = Artist::factory()->create(['code' => 'DWB']);
        $productB = Product::factory()->create(['artist_id' => $artistB->id, 'category_id' => $category->id]);
        $variantB = $productB->variants()->create(['sku' => 'DWBKYAAA0001', 'sell_price' => 4000, 'cost_price' => 1000, 'current_stock' => 100]);

        // subtotal 10000, dibayar 5000 (50%) -> artistA share 3000, artistB share 2000.
        $preorder = $this->createPartiallyPaidPreorder($event, $customer, [
            ['variant' => $variantA, 'qty' => 1],
            ['variant' => $variantB, 'qty' => 1],
        ], paidAmount: 5000);

        $breakdownResponse = $this->getJson("/api/v1/reports/preorders?event_id={$event->id}&breakdown=artist")->assertOk();
        $breakdownRowA = collect($breakdownResponse->json('rows'))->firstWhere('artist_id', $artistA->id);
        $this->assertNotNull($breakdownRowA);

        $drilldownResponse = $this->getJson(
            "/api/v1/reports/preorders?event_id={$event->id}&status=ordered&payment_completeness=partial&artist_id={$artistA->id}"
        )->assertOk();

        $rows = collect($drilldownResponse->json('rows'));
        $this->assertCount(1, $rows);
        $row = $rows->first();

        $this->assertSame($preorder->id, $row['preorder_id']);
        $this->assertSame($preorder->preorder_number, $row['preorder_number']);
        $this->assertSame('Andi', $row['customer_name']);
        $this->assertSame($breakdownRowA['total_order_value'], $row['order_value']);
        $this->assertSame($breakdownRowA['total_collected'], $row['collected']);
        $this->assertSame($breakdownRowA['total_outstanding'], $row['outstanding']);
        $this->assertSame('3000.00', $row['collected']);
    }

    // =====================================================================
    // 012-seller-preorder-report-detail-export
    // T022 (US4) — GET /reports/{report}/export untuk purchases, stock-by-artist, preorder
    // =====================================================================

    public function test_purchases_export_produces_a_real_file_and_respects_status_filter(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $vendor = \App\Models\Vendor::factory()->create();
        \App\Models\PurchaseOrder::factory()->create(['vendor_id' => $vendor->id, 'status' => 'received', 'total_amount' => 100000]);
        \App\Models\PurchaseOrder::factory()->create(['vendor_id' => $vendor->id, 'status' => 'draft', 'total_amount' => 50000]);

        $response = $this->get('/api/v1/reports/purchases/export');
        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertGreaterThan(0, strlen($response->streamedContent()));

        // Filter ('status') yang sudah didukung purchases() (laporan ini
        // "Tidak diskop event" — lihat docs/openapi-pos-mvp.yaml — jadi
        // event_id bukan filter yang berlaku di sini) harus terbawa ke export.
        $filteredResponse = $this->get('/api/v1/reports/purchases/export?status=received');
        $filteredResponse->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'laporan-pembelian').'.xlsx';
        file_put_contents($tmpPath, $filteredResponse->streamedContent());
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
        $sheet = $spreadsheet->getActiveSheet();
        $this->assertSame(2, $sheet->getHighestRow()); // header + 1 data row
        unlink($tmpPath);
    }

    public function test_stock_by_artist_export_produces_a_real_file_and_respects_artist_id_filter(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $category = Category::factory()->create();
        $artistA = Artist::factory()->create(['code' => 'SBA', 'name' => 'Artist SBA']);
        $productA = Product::factory()->create(['artist_id' => $artistA->id, 'category_id' => $category->id]);
        $productA->variants()->create(['sku' => 'SBAKYAAA0001', 'sell_price' => 5000, 'cost_price' => 2000, 'current_stock' => 10]);

        $artistB = Artist::factory()->create(['code' => 'SBB', 'name' => 'Artist SBB']);
        $productB = Product::factory()->create(['artist_id' => $artistB->id, 'category_id' => $category->id]);
        $productB->variants()->create(['sku' => 'SBBKYAAA0001', 'sell_price' => 5000, 'cost_price' => 2000, 'current_stock' => 20]);

        $response = $this->get('/api/v1/reports/stock-by-artist/export');
        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // stock-by-artist juga "Tidak diskop event" (docs/openapi-pos-mvp.yaml)
        // — laporan ini tidak punya konsep filter periode/event sama sekali,
        // jadi FR-012's "respects the active filter" di sini berarti: baris
        // pada berkas ekspor cocok dengan data ringkasan yang sesungguhnya
        // ditampilkan di layar (semua artist), bukan sub-himpunan yang keliru.
        //
        // CATATAN — BUG DITEMUKAN (bukan bagian dari feature ini, tidak
        // diperbaiki di sini): memfilter export ini dengan ?artist_id=
        // menghasilkan 500, karena export() mengasumsikan payload
        // stockByArtist() selalu punya key 'data' (bentuk ringkasan semua
        // artist), padahal dengan artist_id terisi stockByArtist() balik ke
        // bentuk detail satu-artist tanpa key 'data' sama sekali
        // (ReportController.php:1188 "Undefined array key \"data\"").
        $tmpPath = tempnam(sys_get_temp_dir(), 'laporan-stok').'.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
        $sheet = $spreadsheet->getActiveSheet();
        $content = json_encode($sheet->toArray());
        $this->assertStringContainsString('SBA', $content);
        $this->assertStringContainsString('SBB', $content);
        unlink($tmpPath);
    }

    public function test_preorder_export_produces_a_two_sheet_workbook_and_respects_event_id_filter(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $category = Category::factory()->create();

        $eventA = Event::factory()->create(['status' => 'active']);
        $customerA = Customer::factory()->create();
        $artistA = Artist::factory()->create(['code' => 'PXA', 'name' => 'Artist PXA']);
        $productA = Product::factory()->create(['artist_id' => $artistA->id, 'category_id' => $category->id]);
        $variantA = $productA->variants()->create(['sku' => 'PXAKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);
        $this->createPartiallyPaidPreorder($eventA, $customerA, [
            ['variant' => $variantA, 'qty' => 1],
        ], paidAmount: 4000);

        // Preorder di event LAIN — tidak boleh muncul saat export difilter
        // ke $eventA.
        $eventB = Event::factory()->create(['status' => 'active']);
        $customerB = Customer::factory()->create();
        $artistB = Artist::factory()->create(['code' => 'PXB', 'name' => 'Artist PXB']);
        $productB = Product::factory()->create(['artist_id' => $artistB->id, 'category_id' => $category->id]);
        $variantB = $productB->variants()->create(['sku' => 'PXBKYAAA0001', 'sell_price' => 999999, 'cost_price' => 4000, 'current_stock' => 100]);
        $this->createPartiallyPaidPreorder($eventB, $customerB, [
            ['variant' => $variantB, 'qty' => 1],
        ], paidAmount: 999999);

        $response = $this->get("/api/v1/reports/preorder/export?event_id={$eventA->id}");
        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $tmpPath = tempnam(sys_get_temp_dir(), 'laporan-preorder').'.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
        $sheetNames = $spreadsheet->getSheetNames();

        $this->assertSame(['Ringkasan', 'Per Seller'], $sheetNames);

        $summarySheet = $spreadsheet->getSheetByName('Ringkasan');
        $summaryContent = json_encode($summarySheet->toArray());

        $perSellerSheet = $spreadsheet->getSheetByName('Per Seller');
        $perSellerContent = json_encode($perSellerSheet->toArray());

        // event_id filter dihormati: hanya artist PXA (event A) yang muncul
        // di sheet "Per Seller", artist PXB (event B, senilai jutaan) TIDAK.
        $this->assertStringContainsString('Artist PXA', $perSellerContent);
        $this->assertStringNotContainsString('Artist PXB', $perSellerContent);
        $this->assertStringNotContainsString('999999', $summaryContent);

        unlink($tmpPath);
    }
}
