<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\StockService;
use App\Support\OutletStockSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 045 Stage 5 (T071/T072/T076) — invarian I1/I2, pemindai sumber, penguncian, dan penulisan langsung stok.
 */
class StockLedgerInvariantTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private function variant(string $sku, int $stock = 0): ProductVariant
    {
        $this->makeOutlets(3);
        $p = Product::factory()->create(['home_outlet_id' => \App\Models\Outlet::first()->id, 'category_id' => Category::factory()->create()->id]);

        return $p->variants()->create(['sku' => $sku, 'variant_name' => 'R', 'sell_price' => 1000, 'cost_price' => 500, 'current_stock' => $stock]);
    }

    public function test_after_randomised_movements_every_balance_equals_its_ledger_and_the_total_equals_the_sum(): void
    {
        $variant = $this->variant('INV-1');
        $outlets = \App\Models\Outlet::pluck('id')->all();
        $service = app(StockService::class);
        mt_srand(45);

        for ($i = 0; $i < 60; $i++) {
            $outlet = $outlets[mt_rand(0, 2)];
            $qty = mt_rand(-4, 6) ?: 3;
            try {
                $service->applyMovement($variant, $outlet, $qty > 0 ? 'purchase' : 'sale', $qty);
            } catch (ValidationException) {
                // saldo outlet itu tidak cukup — ditolak tanpa efek samping
            }
        }

        foreach ($outlets as $outlet) {
            $balance = (int) DB::table('variant_outlet_stock')->where(['variant_id' => $variant->id, 'outlet_id' => $outlet])->value('current_stock');
            $ledger = (int) DB::table('stock_movements')->where(['variant_id' => $variant->id, 'outlet_id' => $outlet])->sum('qty_change');
            $this->assertGreaterThanOrEqual(0, $balance);
            $this->assertSame($ledger, $balance, 'I1 for outlet '.$outlet);
        }
        $this->assertSame((int) DB::table('variant_outlet_stock')->where('variant_id', $variant->id)->sum('current_stock'), $variant->fresh()->current_stock, 'I2');
        $this->artisan('outlets:reconcile', ['--before' => true, '--dir' => sys_get_temp_dir().'/inv-'.uniqid()])->assertExitCode(0);
    }

    public function test_every_application_call_to_the_stock_services_names_an_outlet(): void
    {
        $files = array_merge(
            glob(base_path('app/Services/*.php')), glob(base_path('app/Http/Controllers/Api/*.php')), glob(base_path('database/seeders/*.php')),
        );
        $offenders = [];
        foreach ($files as $file) {
            if (str_ends_with($file, 'StockService.php') || str_ends_with($file, 'MaterialStockService.php')) {
                continue;
            }
            $code = (string) file_get_contents($file);
            // 087 US6 — applyLineMovementIfTracked (pengembalian per snapshot baris) dijaga sama.
            preg_match_all('/->(applyMovement|applyMovementIfTracked|applyLineMovementIfTracked)\(/', $code, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[0] as [$hit, $offset]) {
                $call = substr($code, $offset, 420);
                if (! str_contains($call, 'outletId:') && ! str_contains($call, 'OutletStockSync')) {
                    $offenders[] = basename($file).'@'.$offset;
                }
            }
        }
        $this->assertSame([], $offenders, 'every applyMovement call must pass the outlet explicitly');
    }

    public function test_a_movement_locks_the_variant_row_then_the_outlet_balance_row(): void
    {
        $variant = $this->variant('INV-LOCK', 5);
        $outlet = (int) DB::table('variant_outlet_stock')->where('variant_id', $variant->id)->value('outlet_id');
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            if (stripos($q->sql, 'for update') !== false) {
                $sql[] = $q->sql;
            }
        });

        app(StockService::class)->applyMovement($variant, $outlet, 'sale', -1);

        $this->assertStringContainsString('product_variants', $sql[0] ?? '');
        $this->assertStringContainsString('variant_outlet_stock', $sql[1] ?? '');
    }

    public function test_two_sales_of_the_last_unit_let_exactly_one_through(): void
    {
        $variant = $this->variant('INV-LAST', 1);
        $outlet = (int) DB::table('variant_outlet_stock')->where('variant_id', $variant->id)->value('outlet_id');
        $service = app(StockService::class);
        $ok = 0;

        foreach ([1, 2] as $_) {
            try {
                $service->applyMovement($variant, $outlet, 'sale', -1);
                $ok++;
            } catch (ValidationException) {
            }
        }

        $this->assertSame(1, $ok);
        $this->assertSame(0, (int) DB::table('variant_outlet_stock')->where(['variant_id' => $variant->id, 'outlet_id' => $outlet])->value('current_stock'));
    }

    public function test_a_direct_write_of_the_stock_column_is_booked_at_the_default_outlet_and_keeps_the_total_in_sync(): void
    {
        $variant = $this->variant('INV-SYNC', 7);
        $default = OutletStockSync::defaultOutlet();

        $this->assertSame(7, (int) DB::table('variant_outlet_stock')->where(['variant_id' => $variant->id, 'outlet_id' => $default])->value('current_stock'));

        $variant->update(['current_stock' => 10]);
        $variant->update(['current_stock' => 4]);

        $this->assertSame(4, (int) DB::table('variant_outlet_stock')->where('variant_id', $variant->id)->sum('current_stock'));
        $this->assertSame(4, $variant->fresh()->current_stock);
    }

    public function test_a_new_variant_always_gets_a_balance_row_even_with_zero_stock(): void
    {
        $variant = $this->variant('INV-ZERO', 0);

        $this->assertSame(1, DB::table('variant_outlet_stock')->where('variant_id', $variant->id)->count());
    }

    // 093 — pemakaian produk sebagai bahan resep memakai SALDO yang sama dan buku besar yang sama.
    public function test_recipe_movement_types_keep_every_balance_equal_to_its_ledger(): void
    {
        $variant = $this->variant('INV-R');
        $outlets = \App\Models\Outlet::pluck('id')->all();
        $service = app(StockService::class);
        mt_srand(93);
        $types = ['purchase', 'sale', 'recipe_sale', 'recipe_return', 'recipe_waste', 'adjustment'];

        for ($i = 0; $i < 80; $i++) {
            $outlet = $outlets[mt_rand(0, 2)];
            $type = $types[mt_rand(0, count($types) - 1)];
            $qty = in_array($type, ['purchase', 'recipe_return'], true) ? mt_rand(1, 6) : -mt_rand(1, 4);
            try {
                $service->applyMovement($variant, $outlet, $type, $qty, 'order_item', $i + 1);
            } catch (ValidationException) {
                // saldo tidak cukup: ditolak tanpa efek samping
            }
        }

        foreach ($outlets as $outlet) {
            $rows = DB::table('stock_movements')->where(['variant_id' => $variant->id, 'outlet_id' => $outlet])->orderBy('id')->get();
            $balance = (int) DB::table('variant_outlet_stock')->where(['variant_id' => $variant->id, 'outlet_id' => $outlet])->value('current_stock');
            $this->assertSame((int) $rows->sum('qty_change'), $balance, 'I1 outlet '.$outlet);
            $this->assertGreaterThanOrEqual(0, $balance);
            $prev = 0;
            foreach ($rows as $r) {
                $this->assertSame($prev, (int) $r->stock_before, 'rantai stock_before/after tidak putus');
                $this->assertSame($prev + (int) $r->qty_change, (int) $r->stock_after);
                $prev = (int) $r->stock_after;
            }
        }
        $this->assertGreaterThan(0, DB::table('stock_movements')->whereIn('type', ['recipe_sale', 'recipe_return', 'recipe_waste'])->count(), 'tipe resep ikut teracak');
        $this->assertSame((int) DB::table('variant_outlet_stock')->where('variant_id', $variant->id)->sum('current_stock'), $variant->fresh()->current_stock, 'I2');
    }

    public function test_only_stock_service_writes_variant_stock_rows_and_movements(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'))) as $file) {
            if ($file->getExtension() !== 'php' || str_ends_with((string) $file, 'StockService.php')) {
                continue;
            }
            $code = (string) file_get_contents((string) $file);
            // MaterialStockService/MaterialStockMovement adalah buku besar BAHAN BAKU, bukan varian.
            if (preg_match("/(?<!Material)StockMovement::(create|insert)\\(|DB::table\\('stock_movements'\\)->insert|VariantOutletStock::(create|query\\(\\)->(create|update))\\(/", $code)
                && ! str_contains((string) $file, 'OutletStockSync') && ! str_contains((string) $file, 'MenuStockService')) {
                $offenders[] = str_replace(base_path().'/', '', (string) $file);
            }
        }
        $this->assertSame([], $offenders, 'satu-satunya penulis saldo & buku besar varian adalah StockService (termasuk pemakaian resep 093)');
    }
}
