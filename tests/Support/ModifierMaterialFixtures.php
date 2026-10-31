<?php

namespace Tests\Support;

use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Material;
use App\Models\MaterialOutletStock;
use App\Models\MaterialStockMovement;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VendorMaterialPrice;
use App\Services\MaterialStockService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * 087 — dunia uji bersama: Indomie Kuah dengan tiga grup modifier (Rasa Kuah, Level Pedas, Topping) dan
 * Nasi Goreng yang memakai grup Topping yang SAMA, sehingga bahan Telur dipakai dua menu (FR-004).
 *
 *   SOTO        → 1 bungkus Indomie Soto        Sedang       → (tanpa bahan)
 *   AYAM BAWANG → 1 bungkus Indomie Ayam Bawang Extra Pedas  → 2 gram Cabai Bubuk
 *   Telur       → 1 butir Telur                 Double Telur → 2 butir Telur
 */
trait ModifierMaterialFixtures
{
    protected Outlet $outletA;

    protected Outlet $outletB;

    protected User $cashier;

    protected CashierSession $session;

    protected ProductVariant $indomie;

    protected ProductVariant $nasiGoreng;

    /** @var array<string, Material> */
    protected array $mat = [];

    /** @var array<string, ModifierOption> */
    protected array $opt = [];

    protected function buildModifierMaterialWorld(): void
    {
        $this->outletA = Outlet::factory()->create(['code' => 'WJA', 'name' => 'Warkop A']);
        $this->outletB = Outlet::factory()->create(['code' => 'WJB', 'name' => 'Warkop B']);

        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->session = CashierSession::factory()->create(['user_id' => $this->cashier->id, 'status' => 'open', 'outlet_id' => $this->outletA->id]);

        foreach (['soto' => ['Indomie Soto', 'bungkus'], 'ayam' => ['Indomie Ayam Bawang', 'bungkus'], 'telur' => ['Telur', 'butir'], 'cabai' => ['Cabai Bubuk', 'gram']] as $key => [$name, $unit]) {
            $this->mat[$key] = Material::factory()->create(['name' => $name, 'unit' => $unit, 'current_stock' => 0]);
        }
        VendorMaterialPrice::factory()->create(['material_id' => $this->mat['telur']->id, 'price' => 2500, 'is_preferred' => true]);

        $this->indomie = $this->makeMenu('Indomie Kuah', 15000, 5000);
        $this->nasiGoreng = $this->makeMenu('Nasi Goreng', 20000, 7000);

        $rasa = ModifierGroup::factory()->create(['name' => 'Rasa Kuah', 'selection_type' => 'single', 'is_required' => true, 'min_select' => 1, 'max_select' => 1]);
        $pedas = ModifierGroup::factory()->create(['name' => 'Level Pedas', 'selection_type' => 'single', 'is_required' => false, 'min_select' => 0, 'max_select' => 1]);
        $topping = ModifierGroup::factory()->create(['name' => 'Topping', 'selection_type' => 'multiple', 'is_required' => false, 'min_select' => 0, 'max_select' => null]);

        $this->opt['soto'] = $this->option($rasa, 'SOTO', ['soto' => 1]);
        $this->opt['ayam'] = $this->option($rasa, 'AYAM BAWANG', ['ayam' => 1]);
        $this->opt['sedang'] = $this->option($pedas, 'Sedang', []);
        $this->opt['extra'] = $this->option($pedas, 'Extra Pedas', ['cabai' => 2]);
        $this->opt['telur'] = $this->option($topping, 'Telur', ['telur' => 1], 3000);
        $this->opt['double'] = $this->option($topping, 'Double Telur', ['telur' => 2], 5000);

        $rasa->products()->attach($this->indomie->product_id);
        $pedas->products()->attach($this->indomie->product_id);
        $topping->products()->attach([$this->indomie->product_id, $this->nasiGoreng->product_id]);

        foreach (['soto' => 50, 'ayam' => 50, 'telur' => 100, 'cabai' => 100] as $key => $qty) {
            $this->setStock($key, $this->outletA, $qty);
            $this->setStock($key, $this->outletB, $qty);
        }
    }

    private function makeMenu(string $name, float $price, float $cost): ProductVariant
    {
        $product = Product::factory()->create(['name' => $name, 'home_outlet_id' => $this->outletA->id, 'category_id' => Category::factory()->create()->id]);

        return $product->variants()->create([
            'sku' => strtoupper(Str::random(10)), 'variant_name' => 'Standard',
            'sell_price' => $price, 'cost_price' => $cost, 'current_stock' => 500,
        ]);
    }

    /** @param array<string, float> $materials */
    private function option(ModifierGroup $group, string $name, array $materials, float $delta = 0): ModifierOption
    {
        $option = $group->options()->create(['name' => $name, 'price_delta' => $delta]);
        foreach ($materials as $key => $qty) {
            $option->materials()->create(['material_id' => $this->mat[$key]->id, 'quantity' => $qty]);
        }

        return $option;
    }

    protected function setStock(string $key, Outlet $outlet, float $target): void
    {
        $delta = $target - $this->balance($key, $outlet);
        if (abs($delta) > 0.0005) {
            app(MaterialStockService::class)->applyMovement($this->mat[$key], $outlet->id, 'adjustment', $delta);
        }
    }

    protected function balance(string $key, ?Outlet $outlet = null): float
    {
        return (float) MaterialOutletStock::where(['material_id' => $this->mat[$key]->id, 'outlet_id' => ($outlet ?? $this->outletA)->id])->value('current_stock');
    }

    /** @return array<int, int> */
    protected function opts(string ...$keys): array
    {
        return array_map(fn ($k) => $this->opt[$k]->id, $keys);
    }

    protected function consumptionMovements(?string $type = null)
    {
        return MaterialStockMovement::query()->whereIn('type', $type ? [$type] : ['sale', 'return', 'waste'])->orderBy('id')->get();
    }

    /** @param array<int, array{0: ProductVariant, 1: int, 2: array<int, int>}> $lines [varian, qty, option ids] */
    protected function checkout(array $lines, ?string $localRef = null, int $paid = 500000): TestResponse
    {
        return $this->postJson('/api/v1/orders', [
            'session_id' => $this->session->id,
            'local_ref' => $localRef ?? (string) Str::uuid(),
            'items' => array_map(fn ($l) => ['variant_id' => $l[0]->id, 'qty' => $l[1], 'modifier_option_ids' => $l[2]], $lines),
            'payments' => [['method' => 'cash', 'amount' => $paid]],
        ]);
    }
}
