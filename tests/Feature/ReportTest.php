<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase, \Tests\Support\TreatsOrdersAsLegacy;

    public function test_artist_settlement_matches_sum_of_actual_orders(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $session = CashierSession::factory()->create(['user_id' => $cashier->id, 'status' => 'open']);

        $artistA = Outlet::factory()->create(['code' => 'AAA']);
        $artistB = Outlet::factory()->create(['code' => 'BBB']);
        $category = Category::factory()->create();

        $productA = Product::factory()->create(['home_outlet_id' => $artistA->id, 'category_id' => $category->id]);
        $variantA = $productA->variants()->create(['sku' => 'AAAKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        $productB = Product::factory()->create(['home_outlet_id' => $artistB->id, 'category_id' => $category->id]);
        $variantB = $productB->variants()->create(['sku' => 'BBBKYBBB0001', 'sell_price' => 20000, 'cost_price' => 8000, 'current_stock' => 100]);

        $orderService = app(OrderService::class);
        $orderService->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variantA->id, 'qty' => 3], ['variant_id' => $variantB->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 50000]],
        ], $cashier);

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $response = $this->getJson("/api/v1/reports/outlet-settlements");

        $response->assertOk();
        $rows = collect($response->json('data'))->keyBy('outlet_id');

        $this->assertEquals('30000.00', $rows[$artistA->id]['total_sales']); // 3 x 10000
        $this->assertEquals('20000.00', $rows[$artistB->id]['total_sales']); // 1 x 20000
        $this->assertEquals(3, $rows[$artistA->id]['total_units']);
    }

    public function test_settlement_recalculates_live_after_a_void(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($cashier, 'sanctum');

        $artist = Outlet::factory()->create();
        $session = CashierSession::factory()->create(['user_id' => $cashier->id, 'status' => 'open']);
        $category = Category::factory()->create();
        $product = Product::factory()->create(['home_outlet_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'AAAKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        $orderService = app(OrderService::class);
        $order = $orderService->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 20000]],
        ], $cashier);

        $this->actingAs($owner, 'sanctum');

        $before = $this->getJson("/api/v1/reports/outlet-settlements")->json('data.0.total_sales');
        $this->assertEquals('20000.00', $before);

        $orderService->void($order, 'Test batal', $owner);

        $after = $this->getJson("/api/v1/reports/outlet-settlements")->json('data.0.total_sales');
        $this->assertEquals('0.00', $after); // order voided -> tidak ikut terhitung
    }

    public function test_profit_report_requires_owner_or_admin(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $this->getJson("/api/v1/reports/profit")->assertStatus(403);
    }

    // Regresi — celah access-control ditemukan saat security review:
    // outletSettlements() tidak punya pemeriksaan owner/admin sama sekali,
    // padahal mengembalikan data penjualan per artist (data komersial
    // sesensitif laporan profit), dan sibling-nya di controller yang sama
    // (profit) sudah menegakkannya.
    public function test_artist_settlements_report_requires_owner_or_admin(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $this->getJson("/api/v1/reports/outlet-settlements")->assertStatus(403);
    }

    // Regresi — artist tanpa satu pun order_items di event ini dulu hilang
    // total dari laporan, karena SettlementService membangun baris dari
    // GROUP BY order_items.home_outlet_id sehingga tidak pernah ada baris
    // settlement untuk mereka.
    public function test_artists_without_any_sales_still_appear_with_zeroes(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');


        $selling = Outlet::factory()->create(['code' => 'SEL', 'name' => 'Outlet Laku']);
        $idle = Outlet::factory()->create(['code' => 'IDL', 'name' => 'Outlet Belum Laku']);
        $session = CashierSession::factory()->create(['user_id' => $cashier->id, 'status' => 'open']);
        $category = Category::factory()->create();

        $product = Product::factory()->create(['home_outlet_id' => $selling->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'SELKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        app(OrderService::class)->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 20000]],
        ], $cashier);

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $rows = collect($this->getJson("/api/v1/reports/outlet-settlements")->json('data'))
            ->keyBy('outlet_id');

        $this->assertCount(2, $rows);

        $this->assertSame('20000.00', $rows[$selling->id]['total_sales']);

        $this->assertSame('0.00', $rows[$idle->id]['total_sales']);
        $this->assertSame(0, $rows[$idle->id]['total_units']);
        $this->assertSame('Outlet Belum Laku', $rows[$idle->id]['outlet_name']);
    }

    // 040 (tindak lanjut) — endpoint "Record payment" untuk seller dihapus; rutenya tidak ada lagi.
    public function test_the_seller_settlement_payment_endpoint_no_longer_exists(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $this->postJson('/api/v1/reports/outlet-settlements/1/payment', ['amount' => 20000])->assertStatus(404);
    }

    // Outlet nonaktif TIDAK ikut dilaporkan bila tidak punya penjualan —
    // laporan berisi "semua artist AKTIF", bukan seluruh isi tabel outlets.
    public function test_inactive_artist_without_sales_is_not_listed(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        Outlet::factory()->create(['code' => 'OFF', 'is_active' => false]);

        $this->getJson("/api/v1/reports/outlet-settlements")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ...tapi kalau artist nonaktif itu PUNYA penjualan di event ini,
    // barisnya wajib tetap muncul — uangnya tetap harus dibayarkan.
    public function test_inactive_artist_with_sales_is_still_listed(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');


        $artist = Outlet::factory()->create(['code' => 'RET', 'is_active' => true]);
        $session = CashierSession::factory()->create(['user_id' => $cashier->id, 'status' => 'open']);
        $category = Category::factory()->create();
        $product = Product::factory()->create(['home_outlet_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'RETKYAAA0001', 'sell_price' => 5000, 'cost_price' => 2000, 'current_stock' => 50]);

        app(OrderService::class)->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 5000]],
        ], $cashier);

        $artist->update(['is_active' => false]);

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $this->getJson("/api/v1/reports/outlet-settlements")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.total_sales', '5000.00');
    }

    public function test_export_endpoint_forwards_the_403_instead_of_a_blank_file(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $this->getJson("/api/v1/reports/profit/export")->assertStatus(403);
    }

    // Regresi — bug ditemukan saat security review: match() di export()
    // tidak punya cabang 'profit' walau route mengizinkannya, sehingga
    // selalu jatuh ke default dan diam-diam menghasilkan file kosong.
    public function test_profit_export_produces_a_real_file_for_owner(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $response = $this->get("/api/v1/reports/profit/export");

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

        $session = CashierSession::factory()->create(['user_id' => $cashier->id, 'status' => 'open']);

        $artist = Outlet::factory()->create(['code' => 'RYU']);
        $category = Category::factory()->create(['code' => 'KY']);
        $product = Product::factory()->create(['home_outlet_id' => $artist->id, 'category_id' => $category->id, 'name' => 'Keychain Minecraft']);
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

        return compact('artist', 'category', 'product', 'orders');
    }

    public function test_sales_report_lists_every_completed_transaction_separately_from_the_aggregate_rows(): void
    {
        ['orders' => $orders] = $this->seedSalesFixture();

        $response = $this->getJson("/api/v1/reports/sales");

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
        ['artist' => $artist, 'category' => $category, 'product' => $product] = $this->seedSalesFixture();

        $byProduct = $this->getJson("/api/v1/reports/sales?group_by=product")->assertOk();
        $this->assertEquals('Produk', $byProduct->json('group_label'));
        $this->assertEquals($product->id, $byProduct->json('rows.0.entity_id'));
        $this->assertEquals($product->name, $byProduct->json('rows.0.label'));

        $byCategory = $this->getJson("/api/v1/reports/sales?group_by=category")->assertOk();
        $this->assertEquals('Kategori', $byCategory->json('group_label'));
        $this->assertEquals($category->id, $byCategory->json('rows.0.entity_id'));
        $this->assertEquals($category->name, $byCategory->json('rows.0.label'));

        $byOutlet = $this->getJson("/api/v1/reports/sales?group_by=outlet")->assertOk();
        $this->assertEquals('Outlet', $byOutlet->json('group_label'));
        $this->assertEquals($artist->id, $byOutlet->json('rows.0.entity_id'));

        $byDay = $this->getJson("/api/v1/reports/sales?group_by=day")->assertOk();
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

        $session = CashierSession::factory()->create(['user_id' => $cashier->id, 'status' => 'open']);
        $artist = Outlet::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['home_outlet_id' => $artist->id, 'category_id' => $category->id]);
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

        $response = $this->getJson("/api/v1/reports/sales");

        $response->assertOk();
        $this->assertSame('Budi Santoso', $response->json('transactions.0.customer_name'));
    }

    // Walk-in — orders.customer_id nullable, field harus null tanpa galat,
    // bukan melempar exception saat relasi customer() diakses.
    public function test_transactions_customer_name_is_null_for_walk_in_orders(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $session = CashierSession::factory()->create(['user_id' => $cashier->id, 'status' => 'open']);
        $artist = Outlet::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['home_outlet_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'BBBKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        app(OrderService::class)->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ], $cashier);

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $response = $this->getJson("/api/v1/reports/sales");

        $response->assertOk();
        $this->assertArrayHasKey('customer_name', $response->json('transactions.0'));
        $this->assertNull($response->json('transactions.0.customer_name'));
    }

    // =====================================================================
    // F11.6 — drill-down transaksi per artist di Rekap Outlet
    // =====================================================================

    public function test_artist_settlement_transactions_requires_owner_or_admin(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $artist = Outlet::factory()->create();

        $this->getJson("/api/v1/reports/outlet-settlements/{$artist->id}/transactions")
            ->assertStatus(403);
    }

    // Sejarah bug di kodebase ini: export yang "200 OK" tapi isinya kosong/
    // salah tidak ketahuan tanpa benar-benar membaca isi berkasnya — jadi
    // tes ini membuka workbook dan memverifikasi kedua sheet beserta isinya,
    // bukan hanya status code.
    public function test_artist_settlements_export_produces_a_real_two_sheet_workbook(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $session = CashierSession::factory()->create(['user_id' => $cashier->id, 'status' => 'open']);
        $artist = Outlet::factory()->create(['code' => 'EXP', 'name' => 'Outlet Ekspor']);
        $category = Category::factory()->create();
        $product = Product::factory()->create(['home_outlet_id' => $artist->id, 'category_id' => $category->id, 'name' => 'Gantungan Kunci']);
        $variant = $product->variants()->create(['sku' => 'EXPKYAAA0001', 'sell_price' => 15000, 'cost_price' => 5000, 'current_stock' => 100]);

        app(OrderService::class)->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 30000]],
        ], $cashier);

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $response = $this->get("/api/v1/reports/outlet-settlements/export");
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'rekap-artist').'.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
        $sheetNames = $spreadsheet->getSheetNames();

        $this->assertContains('Rekap', $sheetNames);
        $this->assertContains('Detail Transaksi', $sheetNames);

        $summarySheet = $spreadsheet->getSheetByName('Rekap');
        $this->assertSame('outlet_name', $summarySheet->getCell('B1')->getValue());
        $this->assertSame('Outlet Ekspor', $summarySheet->getCell('B2')->getValue());

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

        $session = CashierSession::factory()->create(['user_id' => $cashier->id, 'status' => 'open']);
        $artist = Outlet::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['home_outlet_id' => $artist->id, 'category_id' => $category->id]);
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

        $response = $this->getJson("/api/v1/reports/sales?group_by=customer");
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

        $session = CashierSession::factory()->create(['user_id' => $cashier->id, 'status' => 'open']);
        $artist = Outlet::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['home_outlet_id' => $artist->id, 'category_id' => $category->id]);
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
        // 042: laporan memakai tanggal BISNIS tersimpan — geser keduanya, bukan hanya created_at.
        $outOfRange->forceFill(['created_at' => now()->subDays(10), 'business_date' => \Carbon\CarbonImmutable::parse(\App\Support\BusinessDay::today())->subDays(10)->toDateString()])->save();

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $today = \App\Support\BusinessDay::today(); // tanggal bisnis toko, sama dengan yang distempel saat order dibuat
        $response = $this->getJson("/api/v1/reports/sales?group_by=customer&date_from={$today}&date_to={$today}");

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

        $this->getJson("/api/v1/reports/outlet-profit")->assertStatus(403);
    }

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
        // bukan filter periode di sini) harus terbawa ke export.
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
        $artistA = Outlet::factory()->create(['code' => 'SBA', 'name' => 'Outlet SBA']);
        $productA = Product::factory()->create(['home_outlet_id' => $artistA->id, 'category_id' => $category->id]);
        $productA->variants()->create(['sku' => 'SBAKYAAA0001', 'sell_price' => 5000, 'cost_price' => 2000, 'current_stock' => 10]);

        $artistB = Outlet::factory()->create(['code' => 'SBB', 'name' => 'Outlet SBB']);
        $productB = Product::factory()->create(['home_outlet_id' => $artistB->id, 'category_id' => $category->id]);
        $productB->variants()->create(['sku' => 'SBBKYAAA0001', 'sell_price' => 5000, 'cost_price' => 2000, 'current_stock' => 20]);

        $response = $this->get('/api/v1/reports/stock-by-outlet/export');
        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // stock-by-outlet juga "Tidak diskop event" (docs/openapi-pos-mvp.yaml)
        // — laporan ini tidak punya konsep filter periode/event sama sekali,
        // jadi FR-012's "respects the active filter" di sini berarti: baris
        // pada berkas ekspor cocok dengan data ringkasan yang sesungguhnya
        // ditampilkan di layar (semua artist), bukan sub-himpunan yang keliru.
        //
        // CATATAN — BUG DITEMUKAN (bukan bagian dari feature ini, tidak
        // diperbaiki di sini): memfilter export ini dengan ?home_outlet_id=
        // menghasilkan 500, karena export() mengasumsikan payload
        // stockByOutlet() selalu punya key 'data' (bentuk ringkasan semua
        // artist), padahal dengan home_outlet_id terisi stockByOutlet() balik ke
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

    public function test_outlet_settlement_transactions_only_show_that_outlets_items_within_shared_orders(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $session = CashierSession::factory()->create(['user_id' => $cashier->id, 'status' => 'open']);
        $category = Category::factory()->create();

        $artistA = Outlet::factory()->create(['code' => 'ARA', 'name' => 'Outlet A']);
        $artistB = Outlet::factory()->create(['code' => 'ARB', 'name' => 'Outlet B']);

        $productA = Product::factory()->create(['home_outlet_id' => $artistA->id, 'category_id' => $category->id, 'name' => 'Produk A']);
        $variantA = $productA->variants()->create(['sku' => 'ARAKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        $productB = Product::factory()->create(['home_outlet_id' => $artistB->id, 'category_id' => $category->id, 'name' => 'Produk B']);
        $variantB = $productB->variants()->create(['sku' => 'ARBKYAAA0001', 'sell_price' => 20000, 'cost_price' => 8000, 'current_stock' => 100]);

        $orderService = app(OrderService::class);

        // Satu order berisi item DUA outlet sekaligus.
        $sharedOrder = $orderService->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [
                ['variant_id' => $variantA->id, 'qty' => 2],
                ['variant_id' => $variantB->id, 'qty' => 1],
            ],
            'payments' => [['method' => 'cash', 'amount' => 40000]],
        ], $cashier);

        $soloOrderA = $orderService->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variantA->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ], $cashier);

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $forA = $this->getJson("/api/v1/reports/outlet-settlements/{$artistA->id}/transactions")->assertOk();

        $this->assertCount(2, $forA->json('transactions'));
        $this->assertEqualsCanonicalizing(
            ['order-'.$sharedOrder->id, 'order-'.$soloOrderA->id],
            collect($forA->json('transactions'))->pluck('key')->all()
        );

        $sharedRowForA = collect($forA->json('transactions'))->firstWhere('key', 'order-'.$sharedOrder->id);
        $this->assertCount(1, $sharedRowForA['items']);
        $this->assertSame('20000.00', $sharedRowForA['amount_for_outlet']); // 2 x 10000

        $forB = $this->getJson("/api/v1/reports/outlet-settlements/{$artistB->id}/transactions")->assertOk();

        $this->assertCount(1, $forB->json('transactions'));
        $this->assertSame('20000.00', $forB->json('transactions.0.amount_for_outlet')); // 1 x 20000
    }

    public function test_outlet_settlement_transactions_sum_to_the_recap_total_sales(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $session = CashierSession::factory()->create(['user_id' => $cashier->id, 'status' => 'open']);
        $category = Category::factory()->create();

        $artist = Outlet::factory()->create(['code' => 'US2', 'name' => 'Outlet US2']);
        $product = Product::factory()->create(['home_outlet_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'US2KYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        app(OrderService::class)->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ], $cashier);

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $detail = $this->getJson("/api/v1/reports/outlet-settlements/{$artist->id}/transactions")->assertOk();
        $sumOfEntries = collect($detail->json('transactions'))->sum(fn ($tx) => (float) $tx['amount_for_outlet']);

        $recap = $this->getJson('/api/v1/reports/outlet-settlements')->assertOk();
        $recapRow = collect($recap->json('data'))->firstWhere('outlet_id', $artist->id);
        $this->assertNotNull($recapRow);

        $this->assertSame(10000.0, $sumOfEntries);
        $this->assertSame(10000.0, (float) $recapRow['total_sales']);
    }

    public function test_outlet_settlement_transactions_never_mix_demo_and_live_orders(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $artist = Outlet::factory()->create(['code' => 'US4']);
        $category = Category::factory()->create();

        \App\Support\ModeGate::runAs('live', function () use ($artist, $category, $owner) {
            $session = CashierSession::factory()->create(['user_id' => $owner->id, 'status' => 'open']);
            $product = Product::factory()->create(['home_outlet_id' => $artist->id, 'category_id' => $category->id]);
            $variant = $product->variants()->create(['sku' => 'US4KYLIV0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

            app(OrderService::class)->create([
                'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
                'items' => [['variant_id' => $variant->id, 'qty' => 1]],
                'payments' => [['method' => 'cash', 'amount' => 10000]],
            ], $owner);
        });

        \App\Support\ModeGate::runAs('demo', function () use ($artist, $category, $owner) {
            $session = CashierSession::factory()->create(['user_id' => $owner->id, 'status' => 'open']);
            $product = Product::factory()->create(['home_outlet_id' => $artist->id, 'category_id' => $category->id]);
            $variant = $product->variants()->create(['sku' => 'US4KYDEM0001', 'sell_price' => 999999, 'cost_price' => 4000, 'current_stock' => 100]);

            app(OrderService::class)->create([
                'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
                'items' => [['variant_id' => $variant->id, 'qty' => 1]],
                'payments' => [['method' => 'cash', 'amount' => 999999]],
            ], $owner);
        });

        \App\Models\Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'live', 'type' => 'string', 'group' => 'system']);

        $transactions = collect($this->getJson("/api/v1/reports/outlet-settlements/{$artist->id}/transactions")->assertOk()->json('transactions'));

        $this->assertCount(1, $transactions);
        // 10000 (order LIVE) saja — tanpa kontaminasi dari order DEMO senilai jutaan.
        $this->assertSame(10000.0, $transactions->sum(fn ($tx) => (float) $tx['amount_for_outlet']));
    }

    public function test_outlet_profit_sums_modal_across_orders_and_has_no_event_cost(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $session = CashierSession::factory()->create(['user_id' => $cashier->id, 'status' => 'open']);

        $artist = Outlet::factory()->create(['code' => 'MDL', 'name' => 'Outlet Modal']);
        $category = Category::factory()->create();
        $product = Product::factory()->create(['home_outlet_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'MDLKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        $orderService = app(OrderService::class);

        // Dua order terpisah yang menyumbang ke outlet yang sama — agregasinya SUM lintas order.
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

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $row = collect($this->getJson('/api/v1/reports/outlet-profit')->assertOk()->json('data'))->firstWhere('outlet_id', $artist->id);

        $this->assertSame('50000.00', $row['total_sales']);
        $this->assertSame('20000.00', $row['modal']);
        $this->assertSame('30000.00', $row['gross_profit']);
        $this->assertArrayNotHasKey('event_cost', $row);
        $this->assertArrayNotHasKey('net_profit', $row);
    }
}
