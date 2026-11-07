<?php

namespace Tests\Feature;

use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Services\MenuStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 092 (research D5, D12) — penjaga kapasitas mengambil kunci baris bahan BERURUTAN id (Material lalu saldo outlet),
 * urutan yang sama dengan MaterialStockService, sehingga dua pesanan yang memakai bahan sama dalam urutan baris
 * terbalik tidak saling menunggu (deadlock).
 *
 * PENTING — tes ini BUKAN bukti keserentakan sebenarnya. PHPUnit berjalan satu proses; ia hanya mengunci BENTUK
 * kueri kunci. Keserentakan MySQL sungguhan diuji manual (quickstart.md "Uji dua terminal", tugas T044).
 */
class RecipeMenuStockLockOrderTest extends TestCase
{
    use ModifierMaterialFixtures, OverrideFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
    }

    private function recipe(ProductVariant $variant, array $lines): void
    {
        $recipe = Recipe::create(['product_id' => $variant->product_id, 'name' => 'Dasar', 'yield' => 1, 'effective_from' => now()->subDay()->toDateString(), 'created_by' => $this->cashier->id]);
        $recipe->variants()->sync([$variant->id]);
        $block = $recipe->blocks()->create(['variant_id' => $variant->id, 'modifier_option_id' => null, 'display_order' => 1]);
        $i = 0;
        foreach ($lines as $key => $qty) {
            $block->lines()->create(['recipe_id' => $recipe->id, 'material_id' => $this->mat[$key]->id, 'quantity' => $qty, 'shrinkage_pct' => 0, 'display_order' => ++$i]);
        }
    }

    /** @return list<string> kueri `for update` selama blok dijalankan */
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

    public function test_locks_are_taken_material_first_then_balance_both_ordered_by_id(): void
    {
        // Resep menyebut bahan dalam urutan TERBALIK (cabai dulu, telur kemudian): kunci tetap harus urut id.
        $this->recipe($this->nasiGoreng, ['cabai' => 1, 'telur' => 1]);
        $order = $this->makeOpenOrder($this->session, $this->cashier);
        $line = $this->addLineTo($order, $this->nasiGoreng, 1);

        $locks = $this->lockQueries(fn () => DB::transaction(fn () => app(MenuStockService::class)->assertCanFulfil($order, collect([$line]))));

        $materialLock = collect($locks)->search(fn ($s) => str_contains($s, 'from `materials`') && str_contains($s, 'order by `id`'));
        $balanceLock = collect($locks)->search(fn ($s) => str_contains($s, 'material_outlet_stock') && str_contains($s, 'order by `material_id`'));

        $this->assertNotFalse($materialLock, 'bahan dikunci berurutan id: '.json_encode($locks));
        $this->assertNotFalse($balanceLock, 'saldo outlet dikunci berurutan material_id: '.json_encode($locks));
        $this->assertLessThan($balanceLock, $materialLock, 'Material dikunci SEBELUM saldo outlet, sama dengan MaterialStockService');
    }

    public function test_the_guard_takes_no_lock_when_no_line_needs_a_recipe_ingredient(): void
    {
        $order = $this->makeOpenOrder($this->session, $this->cashier);
        $line = $this->addLineTo($order, $this->indomie, 1);

        $locks = $this->lockQueries(fn () => DB::transaction(fn () => app(MenuStockService::class)->assertCanFulfil($order, collect([$line]))));

        $this->assertSame([], $locks, 'menu tanpa resep tidak boleh menambah kunci pada jalur pesanan');
    }

    public function test_the_isolation_switch_is_skipped_inside_an_existing_transaction(): void
    {
        // Pembungkus tes sudah membuka transaksi; `SET TRANSACTION` di dalamnya akan galat 1568, jadi harus dilewati.
        $this->assertGreaterThan(0, DB::transactionLevel());

        $result = app(MenuStockService::class)->transaction(fn () => 'ok');

        $this->assertSame('ok', $result);
    }
}
