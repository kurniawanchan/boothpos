<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 028 US4 — stasiun persiapan (Dapur, Bar) dan penugasannya ke kategori/produk
 * (FR-028). Tulis: `dining_setup`; baca daftar: `dining_setup` ATAU `kitchen`
 * (layar dapur butuh daftar stasiun untuk pengalih).
 */
class StationsTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inventory = User::factory()->create(['role' => 'inventory']);
        $this->actingAs($this->inventory, 'sanctum');
    }

    public function test_a_station_is_created_listed_updated_and_deactivated(): void
    {
        $id = $this->postJson('/api/v1/stations', ['name' => 'Dapur', 'display_order' => 1])
            ->assertCreated()->assertJsonPath('name', 'Dapur')->assertJsonPath('is_active', true)->json('id');

        $this->postJson('/api/v1/stations', ['name' => 'Bar', 'display_order' => 2])->assertCreated();
        $names = collect($this->getJson('/api/v1/stations')->assertOk()->json('data'))->pluck('name');
        $this->assertSame(['Dapur', 'Bar'], $names->all());

        $this->putJson("/api/v1/stations/{$id}", ['name' => 'Dapur Utama', 'is_active' => false])
            ->assertOk()->assertJsonPath('name', 'Dapur Utama')->assertJsonPath('is_active', false);
    }

    public function test_the_active_filter_hides_inactive_stations(): void
    {
        $this->makeStation('Dapur');
        $this->makeStation('Lama', ['is_active' => false]);

        $names = collect($this->getJson('/api/v1/stations?active=1')->json('data'))->pluck('name');

        $this->assertSame(['Dapur'], $names->all());
    }

    public function test_names_are_required_and_unique_per_data_mode(): void
    {
        $this->postJson('/api/v1/stations', [])->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->makeStation('Dapur');
        $this->postJson('/api/v1/stations', ['name' => 'Dapur'])->assertStatus(422)->assertJsonValidationErrors(['name']);

        // Nama yang sama di mode lain tidak bentrok (dan sebaliknya).
        ModeGate::runAs('demo', fn () => $this->makeStation('Bar'));
        $this->postJson('/api/v1/stations', ['name' => 'Bar'])->assertCreated();
    }

    public function test_stations_of_the_other_data_mode_are_invisible(): void
    {
        ModeGate::runAs('demo', fn () => $this->makeStation('DEMO-ONLY'));
        $this->makeStation('LIVE-ONLY');

        $this->assertSame(['LIVE-ONLY'], collect($this->getJson('/api/v1/stations')->json('data'))->pluck('name')->all());
    }

    public function test_an_unused_station_can_be_deleted_and_the_deletion_is_logged(): void
    {
        $station = $this->makeStation('Sementara');

        $this->deleteJson("/api/v1/stations/{$station->id}")->assertNoContent();

        $this->assertDatabaseMissing('stations', ['id' => $station->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'deleted', 'entity_type' => 'Station', 'entity_id' => $station->id]);
    }

    public function test_a_station_assigned_to_a_category_or_a_product_cannot_be_deleted(): void
    {
        $viaCategory = $this->makeStation('A');
        $viaProduct = $this->makeStation('B');
        $this->makeMenuItem(categoryStation: $viaCategory);
        $this->makeMenuItem(productStation: $viaProduct);

        $this->deleteJson("/api/v1/stations/{$viaCategory->id}")->assertStatus(409);
        $this->deleteJson("/api/v1/stations/{$viaProduct->id}")->assertStatus(409);
        $this->assertDatabaseCount('stations', 2);
    }

    public function test_a_category_and_a_product_can_be_assigned_a_station_and_cleared(): void
    {
        $station = $this->makeStation('Bar');
        $category = Category::factory()->create();
        $variant = $this->makeVariant();
        $product = $variant->product;

        $this->putJson("/api/v1/categories/{$category->id}", ['name' => $category->name, 'station_id' => $station->id])
            ->assertOk()->assertJsonPath('station_id', $station->id);
        $this->putJson("/api/v1/products/{$product->id}", ['name' => $product->name, 'station_id' => $station->id])
            ->assertOk()->assertJsonPath('station_id', $station->id);

        $this->putJson("/api/v1/products/{$product->id}", ['name' => $product->name, 'station_id' => null])->assertOk()->assertJsonPath('station_id', null);
        $this->assertNull($product->fresh()->station_id);
    }

    public function test_assigning_an_unknown_or_other_mode_station_is_rejected(): void
    {
        $product = $this->makeVariant()->product;
        $demo = ModeGate::runAs('demo', fn () => $this->makeStation('DEMO'));

        $this->putJson("/api/v1/products/{$product->id}", ['name' => $product->name, 'station_id' => 999999])->assertStatus(422)->assertJsonValidationErrors(['station_id']);
        $this->putJson("/api/v1/products/{$product->id}", ['name' => $product->name, 'station_id' => $demo->id])->assertStatus(422)->assertJsonValidationErrors(['station_id']);
    }

    public function test_the_product_list_carries_the_station(): void
    {
        $station = $this->makeStation('Dapur');
        $this->makeMenuItem(productStation: $station);

        $this->getJson('/api/v1/products')->assertJsonPath('data.0.station_id', $station->id);
    }

    public function test_kitchen_staff_may_read_the_list_but_not_change_stations(): void
    {
        $station = $this->makeStation('Dapur');
        $kitchenOnly = User::factory()->create(['role_id' => Role::factory()->create(['menu_keys' => ['kitchen']])->id]);
        $this->actingAs($kitchenOnly, 'sanctum');

        $this->getJson('/api/v1/stations')->assertOk()->assertJsonPath('data.0.name', 'Dapur');
        $this->postJson('/api/v1/stations', ['name' => 'X'])->assertForbidden();
        $this->putJson("/api/v1/stations/{$station->id}", ['name' => 'Y'])->assertForbidden();
        $this->deleteJson("/api/v1/stations/{$station->id}")->assertForbidden();
    }

    public function test_a_role_with_neither_key_is_refused_everywhere(): void
    {
        $station = $this->makeStation('Dapur');
        $nobody = User::factory()->create(['role_id' => Role::factory()->create(['menu_keys' => ['dashboard', 'pos']])->id]);
        $this->actingAs($nobody, 'sanctum');

        $this->getJson('/api/v1/stations')->assertForbidden();
        $this->postJson('/api/v1/stations', ['name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/v1/stations/{$station->id}")->assertForbidden();
    }
}
