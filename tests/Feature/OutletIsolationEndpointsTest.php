<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Material;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\StockTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 045 Stage 8 (T101) — matriks isolasi: pengguna yang hanya melihat outlet A TIDAK PERNAH menerima data yang hanya milik
 * outlet B, lewat SEMUA jalur baca outlet-scoped (daftar, detail, angka, ekspor, transfer, stok, laporan) — dan header
 * X-Outlet-Id palsu ditolak. Penanda B: nama/kode/nomor yang unik ("ZET").
 */
class OutletIsolationEndpointsTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private Outlet $a;

    private Outlet $b;

    private Outlet $c;


    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->outletModel()::factory()->create(['code' => 'ALF', 'name' => 'Alfa Outlet']);
        $this->b = $this->outletModel()::factory()->create(['code' => 'ZET', 'name' => 'Zeta Outlet']);
        $this->c = $this->outletModel()::factory()->create(['code' => 'GAM', 'name' => 'Gama Outlet']);

        $p = Product::factory()->create(['home_outlet_id' => $this->a->id, 'category_id' => Category::factory()->create()->id, 'name' => 'Produk Uji']);
        $variant = $p->variants()->create(['sku' => 'ISO-1', 'variant_name' => 'R', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 0, 'low_stock_alert' => 99]);
        $this->stockAt($variant, $this->a, 20);
        $this->stockAt($variant, $this->b, 20);
        $material = Material::factory()->create(['current_stock' => 0]);
        app(\App\Services\MaterialStockService::class)->applyMovement(material: $material, outletId: $this->b->id, type: 'adjustment', qtyChange: 5.0);

        foreach ([$this->a, $this->b] as $o) {
            $cashier = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$o]);
            $this->orderAt($this->shiftAt($o, $cashier), $variant, 2);
            $this->tableAt($o, 'Meja '.$o->code);
        }
        // Transfer yang HANYA menyentuh B dan C (tak terlihat oleh pengguna A).
        app(StockTransferService::class)->dispatch(User::factory()->create(['role' => 'owner']), $this->b->id, $this->c->id, [['kind' => 'variant', 'item_id' => $variant->id, 'qty' => 3]]);
    }

    /** Pengguna terbatas ke SATU outlet, dengan menu luas dan semua izin transfer (bukan admin). */
    private function scopedTo(Outlet $outlet): User
    {
        $role = Role::create([
            'name' => 'Matriks '.uniqid(), 'all_outlets' => false,
            'menu_keys' => ['dashboard', 'sales', 'floor', 'stock', 'products', 'reports', 'materials', 'outlets', 'users', 'dining_setup', 'preorders', 'session', 'pos'],
            'permissions' => ['stock.transfer.dispatch', 'stock.transfer.receive', 'stock.transfer.cancel', 'menu.item.manage'],
        ]);

        return $this->assignUser(User::factory()->create(['role_id' => $role->id]), [$outlet]);
    }

    /** @return array<string, string> label => URL */
    private function readEndpoints(): array
    {

        return [
            'orders' => '/api/v1/orders',
            'floor' => '/api/v1/floor',
            'dining tables' => '/api/v1/dining-tables',
            'stations' => '/api/v1/stations',
            'stock movements' => '/api/v1/stock/movements',
            'stock balances' => '/api/v1/stock/balances',
            'stock low' => '/api/v1/stock/low',
            'transfers' => '/api/v1/stock/transfers',
            'in transit' => '/api/v1/stock/in-transit',
            'discrepancies' => '/api/v1/stock/transfers/discrepancies',
            'materials' => '/api/v1/materials',
            'report sales' => "/api/v1/reports/sales",
            'report recap' => "/api/v1/reports/outlet-settlements",
            'report profit' => "/api/v1/reports/outlet-profit",
            'report stock' => '/api/v1/reports/stock-by-outlet',
            'dashboard summary' => '/api/v1/dashboard/summary?period=today',
            'dashboard attention' => '/api/v1/dashboard/attention',
            'dashboard orders' => '/api/v1/dashboard/orders',
            'catalogue' => '/api/v1/products?tab=menu',
            'menu availability' => '/api/v1/menu/availability',
            'export stock' => '/api/v1/exports/stock',
            'export outlet items' => '/api/v1/exports/outlet_items',
            'export outlet prices' => '/api/v1/exports/outlet_prices',
        ];
    }

    public function test_a_user_scoped_to_one_outlet_never_receives_the_other_outlets_data_from_any_read_path(): void
    {
        $this->actingAs($this->scopedTo($this->a), 'sanctum');
        $leaks = [];

        foreach ($this->readEndpoints() as $label => $url) {
            $response = $this->get($url, ['Accept' => 'application/json']);
            $this->assertContains($response->getStatusCode(), [200, 403], "{$label} answered {$response->getStatusCode()}");
            $body = $response->getStatusCode() === 200 ? (string) ($response->baseResponse instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse ? '' : $response->getContent()) : '';
            foreach (['ZET', 'Zeta Outlet', 'Meja ZET', 'GAM', 'Gama Outlet'] as $marker) {
                if ($body !== '' && str_contains($body, $marker)) {
                    $leaks[] = "{$label} leaks '{$marker}'";
                }
            }
        }

        $this->assertSame([], $leaks);
    }

    public function test_exports_for_a_scoped_user_carry_only_their_outlet(): void
    {
        $user = $this->scopedTo($this->a);
        $this->actingAs($user, 'sanctum');
        app(\App\Support\OutletContext::class)->bind($user, null); // sama dengan yang dilakukan middleware pada permintaan nyata
        $rows = app(\App\Services\MasterDataExportService::class);

        // Ekspor master data memakai layanan yang sama dengan endpoint; dipanggil sebagai pengguna terbatas ini.
        $codes = collect($rows->rows('stock'))->pluck('outlet_code')->unique()->values()->all();
        $this->assertSame(['ALF'], $codes, 'stock export must be limited to the user\'s outlets');
        $this->assertSame([], collect($rows->rows('outlet_items'))->pluck('outlet_code')->diff(['ALF'])->values()->all());
        $this->assertSame([], collect($rows->rows('outlet_prices'))->pluck('outlet_code')->diff(['ALF'])->values()->all());
    }

    public function test_a_forged_outlet_header_is_refused_on_every_read_path(): void
    {
        $this->actingAs($this->scopedTo($this->a), 'sanctum');

        foreach ($this->readEndpoints() as $label => $url) {
            $this->withHeader('X-Outlet-Id', (string) $this->b->id)->get($url, ['Accept' => 'application/json'])->assertForbidden("{$label} accepted a forged outlet");
        }
    }

    public function test_direct_requests_for_another_outlets_records_are_not_found_or_forbidden(): void
    {
        $order = \App\Models\Order::withoutGlobalScopes()->where('outlet_id', $this->b->id)->firstOrFail();
        $table = \App\Models\DiningTable::withoutGlobalScopes()->where('outlet_id', $this->b->id)->firstOrFail();
        $transfer = \App\Models\StockTransfer::firstOrFail();
        $this->actingAs($this->scopedTo($this->a), 'sanctum');

        $this->getJson("/api/v1/orders/{$order->id}")->assertNotFound();
        $this->getJson("/api/v1/stock/transfers/{$transfer->id}")->assertNotFound();
        $this->postJson("/api/v1/stock/transfers/{$transfer->id}/cancel")->assertStatus(404);
        $this->getJson('/api/v1/reports/stock-by-outlet?outlet_id='.$this->b->id)->assertForbidden();
        $this->getJson('/api/v1/stock/balances?outlet_id='.$this->b->id)->assertOk()->assertJsonCount(0, 'data');
        $this->assertNotNull($table);
    }

    public function test_owner_and_admin_see_every_outlet(): void
    {
        foreach (['owner', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum');
            $body = $this->getJson('/api/v1/stock/balances')->assertOk()->getContent();
            $this->assertStringContainsString('ZET', $body);
            $this->assertStringContainsString('ALF', $body);
        }
    }
}
