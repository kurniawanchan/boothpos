<?php

namespace Tests\Feature;

use App\Services\MenuStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\IngredientFixtures;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 093 (research D6) — urutan kunci global: bahan baku → saldo bahan baku → varian (id naik) → saldo varian. Penjualan
 * langsung produk bahan (StockService) dan penjaga resep (MenuStockService) memakai urutan yang sama, sehingga dua
 * transaksi tidak saling menunggu melingkar.
 *
 * PENTING — seperti RecipeMenuStockLockOrderTest, ini BUKAN bukti keserentakan; ia mengunci BENTUK kueri kunci dan
 * urutan pemanggilan di sumber. Keserentakan MySQL nyata: tests/Concurrency (dijalankan manual, bukan bagian suite).
 */
class IngredientLockOrderTest extends TestCase
{
    use IngredientFixtures, ModifierMaterialFixtures, OverrideFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
        $this->buildIngredientWorld(['mie' => 100, 'telur' => 50]);
    }

    /** @return list<string> */
    private function lockQueries(callable $work): array
    {
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            if (str_contains(strtolower($q->sql), 'for update')) {
                $sql[] = $q->sql;
            }
        });
        $work();

        return $sql;
    }

    /** Badan sebuah metode dari berkas sumber. */
    private function body(string $file, string $method): string
    {
        $src = file_get_contents(base_path($file));
        $start = strpos($src, "function {$method}(");
        $this->assertNotFalse($start, "{$method} tidak ditemukan di {$file}");
        $next = preg_match('/\n    (public|private|protected) function /', $src, $m, PREG_OFFSET_CAPTURE, $start + 10) ? $m[0][1] : strlen($src);

        return substr($src, $start, $next - $start);
    }

    public function test_the_guard_locks_materials_then_variants_then_variant_balances_each_by_id(): void
    {
        // Resep menyebut bahan produk dalam urutan TERBALIK (telur dulu, mie kemudian) + satu bahan baku.
        $this->ingredientRecipe($this->nasiGoreng, ['telur' => 1, 'mie' => 1, 'm:soto' => 1]);
        $order = $this->makeOpenOrder($this->session, $this->cashier);
        $line = $this->addLineTo($order, $this->nasiGoreng, 1);

        $locks = $this->lockQueries(fn () => DB::transaction(fn () => app(MenuStockService::class)->assertCanFulfil($order, collect([$line]))));

        $find = fn (string $needle, string $orderBy) => collect($locks)->search(fn ($s) => str_contains($s, $needle) && str_contains($s, $orderBy));
        $material = $find('from `materials`', 'order by `id`');
        $materialBalance = $find('material_outlet_stock', 'order by `material_id`');
        $variant = $find('from `product_variants`', 'order by `id`');
        $variantBalance = $find('variant_outlet_stock', 'order by `variant_id`');

        foreach (['material' => $material, 'materialBalance' => $materialBalance, 'variant' => $variant, 'variantBalance' => $variantBalance] as $name => $pos) {
            $this->assertNotFalse($pos, "kunci {$name} tidak ada: ".json_encode($locks));
        }
        $this->assertTrue($material < $materialBalance && $materialBalance < $variant && $variant < $variantBalance, 'urutan global: '.json_encode($locks));
    }

    public function test_the_guard_takes_no_variant_lock_when_only_raw_materials_are_needed(): void
    {
        $this->ingredientRecipe($this->nasiGoreng, ['m:soto' => 1]);
        $order = $this->makeOpenOrder($this->session, $this->cashier);
        $line = $this->addLineTo($order, $this->nasiGoreng, 1);

        $locks = $this->lockQueries(fn () => DB::transaction(fn () => app(MenuStockService::class)->assertCanFulfil($order, collect([$line]))));

        $this->assertEmpty(array_filter($locks, fn ($s) => str_contains($s, 'variant_outlet_stock') || str_contains($s, 'from `product_variants`')), json_encode($locks));
    }

    public function test_lock_ingredients_takes_the_same_locks_before_any_stock_movement(): void
    {
        $this->ingredientRecipe($this->nasiGoreng, ['telur' => 1, 'mie' => 1]);
        $order = $this->makeOpenOrder($this->session, $this->cashier);
        $line = $this->addLineTo($order, $this->nasiGoreng, 1);

        $locks = $this->lockQueries(fn () => DB::transaction(fn () => app(MenuStockService::class)->lockIngredients($order, collect([$line]), [$this->ing['telur']->id])));

        $this->assertNotEmpty(array_filter($locks, fn ($s) => str_contains($s, 'from `product_variants`') && str_contains($s, 'order by `id`')), json_encode($locks));
        $this->assertNotEmpty(array_filter($locks, fn ($s) => str_contains($s, 'variant_outlet_stock')), json_encode($locks));
    }

    public function test_every_flow_that_moves_variant_stock_locks_ingredients_before_the_first_movement(): void
    {
        $create = $this->body('app/Services/OrderService.php', 'create');
        $this->assertGreaterThan(0, strpos($create, 'lockIngredients('), 'OrderService::create harus memanggil lockIngredients');
        $this->assertLessThan(strpos($create, 'applyLineMovementIfTracked('), strpos($create, 'lockIngredients('), 'OrderService::create: kunci dulu, baru pergerakan stok');

        $add = $this->body('app/Services/TabService.php', 'addLine');
        $this->assertLessThan(strpos($add, 'applyLineMovementIfTracked('), strpos($add, 'lockIngredients('), 'TabService::addLine: kunci dulu, baru pergerakan stok');

        $update = $this->body('app/Services/TabService.php', 'updateLine');
        $this->assertLessThan(strpos($update, 'applyLineMovementIfTracked('), strpos($update, 'lockIngredients('), 'TabService::updateLine: kunci dulu, baru pergerakan stok');
    }

    public function test_the_outermost_menu_stock_transaction_retries_on_deadlock(): void
    {
        $this->assertMatchesRegularExpression('/DB::transaction\(\$work,\s*3\)/', $this->body('app/Services/MenuStockService.php', 'transaction'));
    }
}
