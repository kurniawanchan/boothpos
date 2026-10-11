<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 043-products-page-redesign (T032) — fakta item (nama pendek, kategori pajak, persiapan, ritel) dan jenis bahan.
 */
class ProductFactsTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    public function test_facts_are_saved_on_update_and_validated(): void
    {
        $product = $this->makeVariant(stock: 1)->product;

        $this->putJson("/api/v1/products/{$product->id}", ['name' => $product->name, 'short_name' => 'Tubruk', 'tax_category' => 'restaurant_food_beverage', 'prep_minutes' => 3, 'is_retail' => true])->assertOk();
        $fresh = $product->fresh();
        $this->assertSame('Tubruk', $fresh->short_name);
        $this->assertSame(3, $fresh->prep_minutes);
        $this->assertTrue($fresh->is_retail);

        $this->putJson("/api/v1/products/{$product->id}", ['name' => $product->name, 'short_name' => str_repeat('x', 25)])->assertStatus(422);
        $this->putJson("/api/v1/products/{$product->id}", ['name' => $product->name, 'prep_minutes' => 601])->assertStatus(422);
        $this->putJson("/api/v1/products/{$product->id}", ['name' => $product->name, 'prep_minutes' => -1])->assertStatus(422);
    }

    public function test_the_lifecycle_columns_cannot_be_sent_by_a_client(): void
    {
        $product = $this->makeVariant(stock: 1)->product;

        $this->putJson("/api/v1/products/{$product->id}", ['name' => $product->name, 'discontinued_at' => '2020-01-01', 'published_at' => '2020-01-01'])->assertOk();

        $this->assertNull($product->fresh()->discontinued_at);
    }

    public function test_material_kind_and_standard_cost_are_saved_and_filterable(): void
    {
        $this->postJson('/api/v1/materials', ['code' => 'SAUS1', 'name' => 'Saus Sambal', 'unit' => 'ml', 'kind' => 'semi_finished', 'standard_cost' => 12.5])->assertCreated()
            ->assertJsonPath('kind', 'semi_finished')->assertJsonPath('standard_cost', '12.5000');
        $this->postJson('/api/v1/materials', ['code' => 'KOP1', 'name' => 'Biji Kopi', 'unit' => 'g'])->assertCreated()->assertJsonPath('kind', 'ingredient');
        $this->postJson('/api/v1/materials', ['code' => 'BAD1', 'name' => 'Salah', 'unit' => 'g', 'kind' => 'rahasia'])->assertStatus(422);

        $names = array_column($this->getJson('/api/v1/materials?kind=semi_finished')->assertOk()->json('data'), 'name');

        $this->assertSame(['Saus Sambal'], $names);
        $this->assertSame(2, Material::count());
    }
}
