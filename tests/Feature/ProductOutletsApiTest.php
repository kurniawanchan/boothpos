<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 045 Stage 3 (T047, API) — produk membawa `outlet_ids` saat dibuat/diubah, dan responsnya memuat `outlets[]`.
 */
class ProductOutletsApiTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private function body(int $homeOutletId, array $extra = []): array
    {
        return array_merge([
            'home_outlet_id' => $homeOutletId,
            'category_id' => Category::factory()->create()->id,
            'name' => 'Kopi Susu',
            'variants' => [['variant_name' => 'Regular', 'sell_price' => 18000, 'cost_price' => 6000]],
        ], $extra);
    }

    private function states(array $json): array
    {
        return collect($json['outlets'])->pluck('carried', 'id')->all();
    }

    public function test_create_with_outlet_ids_marks_only_those_outlets_as_carried(): void
    {
        [$a, $b] = $this->makeOutlets(2)->all();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $json = $this->postJson('/api/v1/products', $this->body($a->id, ['outlet_ids' => [$a->id]]))->assertCreated()->json();

        $this->assertSame([$a->id => true, $b->id => false], $this->states($json));
    }

    public function test_without_outlet_ids_every_outlet_carries_the_new_product(): void
    {
        [$a, $b] = $this->makeOutlets(2)->all();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $json = $this->postJson('/api/v1/products', $this->body($a->id))->assertCreated()->json();

        $this->assertSame([$a->id => true, $b->id => true], $this->states($json));
    }

    public function test_update_changes_the_carried_set_and_nothing_else_when_omitted(): void
    {
        [$a, $b] = $this->makeOutlets(2)->all();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $id = $this->postJson('/api/v1/products', $this->body($a->id, ['outlet_ids' => [$a->id]]))->json('id');

        $both = $this->putJson("/api/v1/products/{$id}", ['name' => 'Kopi Susu', 'outlet_ids' => [$a->id, $b->id]])->assertOk()->json();
        $this->assertSame([$a->id => true, $b->id => true], $this->states($both));

        $renamed = $this->putJson("/api/v1/products/{$id}", ['name' => 'Kopi Hitam'])->assertOk()->json();
        $this->assertSame('Kopi Hitam', $renamed['name']);
        $this->assertSame([$a->id => true, $b->id => true], $this->states($renamed), 'omitting outlet_ids leaves the state alone');
    }

    public function test_a_single_outlet_install_has_no_outlets_key(): void
    {
        $a = $this->makeOutlets(1)[0];
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $json = $this->postJson('/api/v1/products', $this->body($a->id))->assertCreated()->json();

        $this->assertArrayNotHasKey('outlets', $json);
    }

    public function test_a_manager_can_only_change_the_outlets_they_see(): void
    {
        [$a, $b] = $this->makeOutlets(2)->all();
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');
        $id = $this->postJson('/api/v1/products', $this->body($a->id, ['outlet_ids' => [$b->id]]))->json('id'); // carried only at B

        $role = Role::create(['name' => 'Pengelola Produk', 'menu_keys' => ['dashboard', 'products'], 'all_outlets' => false]);
        $manager = User::factory()->create(['role_id' => $role->id]);
        $this->assignUser($manager, [$a]);
        $this->actingAs($manager, 'sanctum');

        // B tidak terlihat oleh pengelola: ditolak.
        $this->putJson("/api/v1/products/{$id}", ['name' => 'Kopi Susu', 'outlet_ids' => [$b->id]])->assertStatus(422)->assertJsonValidationErrors('outlet_ids.0');

        // A boleh; B (di luar jangkauan) tetap dibawa.
        $this->putJson("/api/v1/products/{$id}", ['name' => 'Kopi Susu', 'outlet_ids' => [$a->id]])->assertOk();
        $this->actingAs($owner, 'sanctum');
        $states = $this->states($this->getJson("/api/v1/products/{$id}")->json());
        $this->assertSame([$a->id => true, $b->id => true], $states);
    }
}
