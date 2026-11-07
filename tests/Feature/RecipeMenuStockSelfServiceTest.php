<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\MaterialStockMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\SelfServiceOrder;
use App\Models\Station;
use App\Models\User;
use App\Services\MaterialStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\OutletFixtures;
use Tests\Support\SelfServiceFixtures;
use Tests\TestCase;

/**
 * 092 US3/US4 (FR-013, research D11) — pesan mandiri QR: menu beresep yang bahannya habis tampil habis di menu publik,
 * kiriman publik melebihi stok ditolak SEBELUM pelanggan membayar (tanpa nama bahan di respons publik), dan dispatch oleh
 * staf yang kekurangan bahan digulung penuh (kiriman tetap `verified`) dengan respons staf yang menyebut bahannya.
 */
class RecipeMenuStockSelfServiceTest extends TestCase
{
    use OutletFixtures;
    use RefreshDatabase;
    use SelfServiceFixtures;

    private User $owner;

    private $coffee;

    private Material $telur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ssSetUp();
        $this->owner = $this->ssOwner();
        $kitchen = $this->makeStation('Dapur');
        $this->coffee = $this->makeMenuItem(categoryStation: $kitchen, stock: 10, price: 20000);
        $this->telur = Material::factory()->create(['name' => 'Telur', 'unit' => 'butir', 'current_stock' => 0]);

        $recipe = Recipe::create(['product_id' => $this->coffee->product_id, 'name' => 'Dasar', 'yield' => 1, 'effective_from' => now()->subDay()->toDateString(), 'created_by' => $this->owner->id]);
        $recipe->variants()->sync([$this->coffee->id]);
        $block = $recipe->blocks()->create(['variant_id' => $this->coffee->id, 'modifier_option_id' => null, 'display_order' => 1]);
        $block->lines()->create(['recipe_id' => $recipe->id, 'material_id' => $this->telur->id, 'quantity' => 1, 'shrinkage_pct' => 0, 'display_order' => 1]);

        $this->setTelur(5);
    }

    private function setTelur(float $target): void
    {
        $outletId = (int) $this->ssTable->outlet_id;
        $current = (float) \App\Models\MaterialOutletStock::where(['material_id' => $this->telur->id, 'outlet_id' => $outletId])->value('current_stock');
        if (abs($target - $current) > 0.0005) {
            app(MaterialStockService::class)->applyMovement($this->telur, $outletId, 'adjustment', $target - $current);
        }
    }

    private function publicMenuProduct(): array
    {
        Cache::flush();

        return collect($this->getJson($this->ssUrl('/menu'))->assertOk()->json('data.products'))->firstWhere('id', $this->coffee->product_id);
    }

    public function test_the_public_menu_marks_a_recipe_menu_sold_out_when_an_ingredient_is_empty(): void
    {
        $this->assertFalse($this->publicMenuProduct()['sold_out']);

        $this->setTelur(0);

        $this->assertTrue($this->publicMenuProduct()['sold_out']);
    }

    public function test_a_public_submission_over_the_stock_is_refused_without_naming_the_ingredient(): void
    {
        Cache::flush();

        $res = $this->postJson($this->ssUrl('/orders'), $this->ssPayload([['variant_id' => $this->coffee->id, 'qty' => 6]]))->assertStatus(409)
            ->assertJsonPath('code', 'insufficient_recipe_stock')->assertJsonPath('limiting.available_portions', 5);

        $this->assertArrayNotHasKey('name', $res->json('limiting'));
        $this->assertArrayNotHasKey('material_id', $res->json('limiting'));
        $this->assertStringNotContainsString('Telur', $res->getContent(), 'nama bahan adalah detail internal toko');
        $this->assertSame(0, SelfServiceOrder::withoutGlobalScopes()->count());
    }

    public function test_a_submission_within_the_stock_is_dispatched_and_deducts_ingredients_not_the_variant(): void
    {
        $this->makeShift($this->owner);
        $placed = $this->ssPlaceOrder([['variant_id' => $this->coffee->id, 'qty' => 2]]);
        $this->actingAs($this->owner, 'sanctum');
        $this->postJson("/api/v1/self-service/orders/{$placed->id}/verify", ['reference' => 'BCA-1234'])->assertOk();

        $this->postJson("/api/v1/self-service/orders/{$placed->id}/dispatch")->assertOk()->assertJsonPath('data.status', 'dispatched');

        $this->assertSame(3.0, (float) \App\Models\MaterialOutletStock::where(['material_id' => $this->telur->id, 'outlet_id' => $this->ssTable->outlet_id])->value('current_stock'));
        $this->assertSame(10, (int) $this->coffee->fresh()->current_stock, 'stok varian menu beresep tidak bergerak');
    }

    public function test_a_dispatch_that_finds_the_ingredient_short_rolls_back_everything(): void
    {
        $this->makeShift($this->owner);
        $placed = $this->ssPlaceOrder([['variant_id' => $this->coffee->id, 'qty' => 4]]);
        $this->actingAs($this->owner, 'sanctum');
        $this->postJson("/api/v1/self-service/orders/{$placed->id}/verify", ['reference' => 'BCA-1234'])->assertOk();

        $this->setTelur(2);   // bahan berkurang antara kiriman dan dispatch

        $this->postJson("/api/v1/self-service/orders/{$placed->id}/dispatch")->assertStatus(409)
            ->assertJsonPath('code', 'insufficient_recipe_stock')->assertJsonPath('limiting.name', 'Telur');

        $this->assertSame('verified', $placed->fresh()->status, 'kiriman tetap bisa dicoba lagi atau ditolak');
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, MaterialStockMovement::where('type', 'sale')->count());
        $this->assertSame(10, (int) $this->coffee->fresh()->current_stock);
    }
}
