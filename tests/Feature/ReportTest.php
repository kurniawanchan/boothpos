<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Event;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
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
}
