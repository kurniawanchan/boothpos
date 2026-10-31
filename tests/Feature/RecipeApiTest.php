<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\OrderItem;
use App\Models\Recipe;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorMaterialPrice;
use App\Services\RecipeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** 043 (T075) — resep berversi: versi baru per simpan, validasi, versi aktif, sumber biaya tunggal, izin. */
class RecipeApiTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private $variant;
    private Material $beans;
    private Material $milk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->variant = $this->makeVariant(stock: 5, price: 50000, variantOverrides: ['cost_price' => 1]);
        $this->beans = $this->material('Biji kopi', 5000);
        $this->milk = $this->material('Susu', 40000);
    }

    private function material(string $name, float $price, array $over = []): Material
    {
        $m = Material::factory()->create(array_merge(['name' => $name, 'unit' => 'kg'], $over));
        if ($price > 0) {
            VendorMaterialPrice::create(['vendor_id' => Vendor::factory()->create()->id, 'material_id' => $m->id, 'price' => $price, 'is_preferred' => true]);
        }

        return $m;
    }

    private function body(array $over = []): array
    {
        return array_merge([
            'yield' => 2, 'effective_from' => now()->toDateString(), 'variant_ids' => [$this->variant->id],
            'lines' => [
                ['material_id' => $this->beans->id, 'quantity' => 2, 'shrinkage_pct' => 0],
                ['material_id' => $this->milk->id, 'quantity' => 0.5, 'shrinkage_pct' => 20],
            ],
        ], $over);
    }

    private function create(array $over = [])
    {
        return $this->postJson("/api/v1/products/{$this->variant->product_id}/recipes", $this->body($over));
    }

    public function test_saving_creates_the_next_version_and_keeps_the_earlier_one(): void
    {
        $first = $this->create()->assertCreated()->assertJsonPath('version', 1)->assertJsonPath('portion_cost', '17500.00')->assertJsonPath('food_cost_pct', 35)->json();
        $this->create(['yield' => 1])->assertCreated()->assertJsonPath('version', 2);

        $list = $this->getJson("/api/v1/products/{$this->variant->product_id}/recipes")->assertOk()->json('data');
        $this->assertSame([2, 1], array_column($list, 'version'));
        $this->assertTrue($list[0]['is_active_now']);
        $this->assertFalse($list[1]['is_active_now']);
        $this->assertSame(2, Recipe::count());
        $this->assertSame(2, Recipe::find($first['id'])->lines()->count());   // versi lama tak berubah
    }

    /**
     * 2026-10-31 (permintaan pemilik: "bisa hapus resep") — hapus resep = hapus SEMUA versinya. Varian berhenti
     * digerakkan resep (harga modal boleh diubah lagi), harga modal terakhir tetap, dan `order_items.cost_price`
     * yang sudah tercatat tidak berubah. Isi versi tersimpan di log aktivitas.
     */
    public function test_removing_the_recipe_deletes_every_version_and_unlocks_the_cost_price(): void
    {
        $this->create()->assertCreated();
        $this->create(['yield' => 1])->assertCreated();
        $this->assertTrue(app(RecipeService::class)->isRecipeDriven($this->variant->fresh()));

        $this->deleteJson("/api/v1/products/{$this->variant->product_id}/recipes")->assertOk()->assertJsonPath('removed_versions', 2);

        $this->assertSame(0, Recipe::count());
        $this->assertFalse(app(RecipeService::class)->isRecipeDriven($this->variant->fresh()));
        $this->putJson("/api/v1/variants/{$this->variant->id}", ['cost_price' => 1234])->assertOk();
        $log = \App\Models\ActivityLog::where('action', 'recipe_removed')->sole();
        $this->assertCount(2, $log->old_values['versions']);

        $this->deleteJson("/api/v1/products/{$this->variant->product_id}/recipes")->assertStatus(404);
    }

    public function test_removing_a_recipe_needs_the_recipe_permission(): void
    {
        $this->create()->assertCreated();
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->deleteJson("/api/v1/products/{$this->variant->product_id}/recipes")->assertForbidden();
        $this->assertSame(1, Recipe::count());
    }

    public function test_the_cost_price_follows_the_recipe_when_it_takes_effect_and_recorded_costs_stay(): void
    {
        $this->create()->assertCreated();

        $this->assertSame('17500.00', number_format((float) $this->variant->fresh()->cost_price, 2, '.', ''));
        $this->assertSame(0, OrderItem::count());
    }

    public function test_a_future_recipe_does_not_touch_the_cost_until_its_date(): void
    {
        $this->create(['effective_from' => now()->addDays(3)->toDateString()])->assertCreated();
        $this->assertEquals(1, (float) $this->variant->fresh()->cost_price);

        $this->travelTo(now()->addDays(4));
        app(RecipeService::class)->applyDue();

        $this->assertSame('17500.00', number_format((float) $this->variant->fresh()->cost_price, 2, '.', ''));
    }

    public function test_validation_rejects_bad_input_and_creates_nothing(): void
    {
        $this->create(['yield' => 0])->assertStatus(422)->assertJsonValidationErrors(['yield']);
        $this->create(['lines' => []])->assertStatus(422)->assertJsonValidationErrors(['lines']);
        $this->create(['variant_ids' => []])->assertStatus(422)->assertJsonValidationErrors(['variant_ids']);
        $this->create(['effective_from' => now()->subDays(31)->toDateString()])->assertStatus(422)->assertJsonValidationErrors(['effective_from']);
        $this->create(['lines' => [['material_id' => $this->beans->id, 'quantity' => 0]]])->assertStatus(422)->assertJsonValidationErrors(['lines.0.quantity']);
        $this->create(['lines' => [['material_id' => $this->beans->id, 'quantity' => 1, 'shrinkage_pct' => 100]]])->assertStatus(422)->assertJsonValidationErrors(['lines.0.shrinkage_pct']);
        $this->create(['lines' => [['material_id' => 999999, 'quantity' => 1]]])->assertStatus(422)->assertJsonValidationErrors(['lines.0.material_id']);
        $this->create(['variant_ids' => [$this->makeVariant()->id]])->assertStatus(422)->assertJsonValidationErrors(['variant_ids']);

        $this->assertSame(0, Recipe::count());
    }

    public function test_a_bom_complete_variant_cannot_join_a_recipe(): void
    {
        $this->variant->update(['bom_complete' => true]);

        $this->create()->assertStatus(409)->assertJsonPath('code', 'cost_source_conflict');
        $this->assertSame(0, Recipe::count());
    }

    public function test_a_recipe_driven_variant_cannot_be_marked_bom_complete(): void
    {
        $this->create()->assertCreated();

        $this->postJson("/api/v1/variants/{$this->variant->id}/bom/complete")->assertStatus(409)->assertJsonPath('code', 'cost_source_conflict');
    }

    public function test_a_recipe_driven_variant_cost_price_is_locked_but_an_unchanged_value_is_accepted(): void
    {
        $this->create()->assertCreated();
        $v = $this->variant->fresh();
        $payload = ['variant_name' => $v->variant_name, 'sell_price' => $v->sell_price, 'cost_price' => 999];

        $this->putJson("/api/v1/variants/{$v->id}", $payload)->assertStatus(409)->assertJsonPath('code', 'cost_price_locked_by_recipe');
        $this->putJson("/api/v1/variants/{$v->id}", ['cost_price' => 17500] + $payload)->assertOk();
    }

    public function test_a_material_without_any_cost_makes_the_portion_incomplete_and_the_cost_untouched(): void
    {
        $free = $this->material('Garam', 0);

        $this->create(['lines' => [['material_id' => $free->id, 'quantity' => 1]]])->assertCreated()->assertJsonPath('incomplete', true)->assertJsonPath('food_cost_pct', null);

        $this->assertEquals(1, (float) $this->variant->fresh()->cost_price);
    }

    public function test_a_semi_finished_material_uses_its_standard_cost(): void
    {
        $syrup = $this->material('Sirup', 0, ['kind' => 'semi_finished', 'standard_cost' => 8000]);

        $this->create(['yield' => 1, 'lines' => [['material_id' => $syrup->id, 'quantity' => 1]]])->assertCreated()->assertJsonPath('portion_cost', '8000.00');
    }

    public function test_recalculate_rewrites_the_cost_from_the_current_vendor_price(): void
    {
        $this->create()->assertCreated();
        VendorMaterialPrice::where('material_id', $this->beans->id)->update(['price' => 6000]);   // 2 × 6.000 + 25.000 = 37.000 ÷ 2

        $this->postJson("/api/v1/products/{$this->variant->product_id}/recipes/recalculate")->assertOk()->assertJsonPath('updated.0.cost_price', '18500.00');

        $this->assertSame('18500.00', number_format((float) $this->variant->fresh()->cost_price, 2, '.', ''));
    }

    public function test_a_user_without_the_recipe_permission_sees_no_cost_and_cannot_write(): void
    {
        $role = Role::factory()->create(['menu_keys' => ['products']]);
        $this->create()->assertCreated();
        $this->actingAs(User::factory()->create(['role_id' => $role->id]), 'sanctum');

        $row = $this->getJson("/api/v1/products/{$this->variant->product_id}/recipes")->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('portion_cost', $row);
        $this->create()->assertForbidden();
        $this->postJson("/api/v1/products/{$this->variant->product_id}/recipes/recalculate")->assertForbidden();
    }

    public function test_a_cashier_cannot_read_recipes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->getJson("/api/v1/products/{$this->variant->product_id}/recipes")->assertForbidden();
    }

    public function test_one_version_returns_its_lines_with_unit_and_line_costs(): void
    {
        $id = $this->create()->json('id');

        $json = $this->getJson("/api/v1/recipes/{$id}")->assertOk()->json();

        $this->assertSame('25000.0000', $json['lines'][1]['line_cost']);
        $this->assertSame('40000.0000', $json['lines'][1]['unit_cost']);
        $this->assertSame('32500.00', $json['gross_margin']);
    }

    public function test_the_product_detail_carries_the_active_recipe_summary(): void
    {
        $this->create()->assertCreated();

        $this->getJson("/api/v1/products/{$this->variant->product_id}")->assertOk()
            ->assertJsonPath('recipe.version', 1)->assertJsonPath('recipe.portion_cost', '17500.00')->assertJsonPath('recipe.line_count', 2);
    }
}
