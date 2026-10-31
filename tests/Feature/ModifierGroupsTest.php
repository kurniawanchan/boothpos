<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 028 US3 — manajemen grup modifier (FR-022, FR-023). Gerbang: `dining_setup`.
 */
class ModifierGroupsTest extends TestCase
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

    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'Size', 'selection_type' => 'single', 'is_required' => true,
            'options' => [['name' => 'Regular', 'price_delta' => 0], ['name' => 'Large', 'price_delta' => 5000]],
        ], $over);
    }

    public function test_a_group_is_created_with_its_options(): void
    {
        $this->postJson('/api/v1/modifier-groups', $this->payload())
            ->assertCreated()
            ->assertJsonPath('name', 'Size')
            ->assertJsonPath('selection_type', 'single')
            ->assertJsonPath('is_required', true)
            ->assertJsonPath('min_select', 1)
            ->assertJsonPath('max_select', 1)
            ->assertJsonCount(2, 'options')
            ->assertJsonPath('options.1.name', 'Large')
            ->assertJsonPath('options.1.price_delta', '5000.00')
            ->assertJsonPath('product_ids', [])
            ->assertJsonPath('category_ids', []);
    }

    public function test_single_choice_rules_are_normalised(): void
    {
        // Pilihan tunggal: max selalu 1; min = 1 bila wajib, 0 bila opsional — apa pun yang dikirim.
        $this->postJson('/api/v1/modifier-groups', $this->payload(['is_required' => false, 'min_select' => 3, 'max_select' => 9]))
            ->assertCreated()->assertJsonPath('min_select', 0)->assertJsonPath('max_select', 1);
    }

    public function test_a_required_multiple_choice_group_needs_at_least_one(): void
    {
        $this->postJson('/api/v1/modifier-groups', $this->payload(['selection_type' => 'multiple', 'is_required' => true, 'min_select' => 0, 'max_select' => 3]))
            ->assertCreated()->assertJsonPath('min_select', 1)->assertJsonPath('max_select', 3);
    }

    public function test_min_greater_than_max_is_rejected(): void
    {
        $this->postJson('/api/v1/modifier-groups', $this->payload(['selection_type' => 'multiple', 'is_required' => false, 'min_select' => 3, 'max_select' => 2]))
            ->assertStatus(422)->assertJsonValidationErrors(['min_select']);
    }

    public function test_an_unbounded_multiple_choice_group_has_a_null_max(): void
    {
        $this->postJson('/api/v1/modifier-groups', $this->payload(['name' => 'Add-ons', 'selection_type' => 'multiple', 'is_required' => false, 'max_select' => null]))
            ->assertCreated()->assertJsonPath('max_select', null);
    }

    public function test_validation_covers_name_type_and_options(): void
    {
        $this->postJson('/api/v1/modifier-groups', [])->assertStatus(422)->assertJsonValidationErrors(['name', 'selection_type', 'options']);
        $this->postJson('/api/v1/modifier-groups', $this->payload(['options' => []]))->assertStatus(422)->assertJsonValidationErrors(['options']);
        $this->postJson('/api/v1/modifier-groups', $this->payload(['options' => [['name' => '', 'price_delta' => 1]]]))->assertStatus(422)->assertJsonValidationErrors(['options.0.name']);
        $this->postJson('/api/v1/modifier-groups', $this->payload(['options' => [['name' => 'X', 'price_delta' => 'abc']]]))->assertStatus(422)->assertJsonValidationErrors(['options.0.price_delta']);
    }

    public function test_an_option_may_have_a_negative_price_difference(): void
    {
        $this->postJson('/api/v1/modifier-groups', $this->payload(['options' => [['name' => 'Tanpa keju', 'price_delta' => -3000]]]))
            ->assertCreated()->assertJsonPath('options.0.price_delta', '-3000.00');
    }

    public function test_updating_replaces_options_by_id_and_deactivates_the_omitted_ones(): void
    {
        $group = $this->postJson('/api/v1/modifier-groups', $this->payload())->json();
        [$regular, $large] = $group['options'];

        $res = $this->putJson("/api/v1/modifier-groups/{$group['id']}", [
            'options' => [
                ['id' => $large['id'], 'name' => 'Besar', 'price_delta' => 6000],   // diubah
                ['name' => 'Jumbo', 'price_delta' => 9000],                        // baru
                // $regular tidak dikirim → dinonaktifkan, BUKAN dihapus
            ],
        ])->assertOk();

        $byId = collect($res->json('options'))->keyBy('id');
        $this->assertSame('Besar', $byId[$large['id']]['name']);
        $this->assertSame('6000.00', $byId[$large['id']]['price_delta']);
        $this->assertFalse($byId[$regular['id']]['is_active']);
        $this->assertTrue($byId->contains('name', 'Jumbo'));
        $this->assertDatabaseHas('modifier_options', ['id' => $regular['id'], 'is_active' => 0]);
    }

    public function test_a_group_can_be_renamed_and_deactivated_without_touching_options(): void
    {
        $group = $this->postJson('/api/v1/modifier-groups', $this->payload())->json();

        $this->putJson("/api/v1/modifier-groups/{$group['id']}", ['name' => 'Ukuran', 'is_active' => false])
            ->assertOk()->assertJsonPath('name', 'Ukuran')->assertJsonPath('is_active', false)->assertJsonCount(2, 'options');
    }

    public function test_attachments_replace_the_products_and_categories_of_a_group(): void
    {
        $group = $this->postJson('/api/v1/modifier-groups', $this->payload())->json();
        $product = Product::factory()->create(['home_outlet_id' => $this->makeVariant()->product->home_outlet_id, 'category_id' => Category::factory()->create()->id]);
        $category = Category::factory()->create();

        $this->putJson("/api/v1/modifier-groups/{$group['id']}/attachments", ['product_ids' => [$product->id], 'category_ids' => [$category->id]])
            ->assertOk()->assertJsonPath('product_ids', [$product->id])->assertJsonPath('category_ids', [$category->id]);

        $this->putJson("/api/v1/modifier-groups/{$group['id']}/attachments", ['product_ids' => [], 'category_ids' => []])
            ->assertOk()->assertJsonPath('product_ids', [])->assertJsonPath('category_ids', []);
    }

    public function test_attachments_refuse_ids_of_the_other_data_mode(): void
    {
        $group = $this->postJson('/api/v1/modifier-groups', $this->payload())->json();
        $demoCategory = ModeGate::runAs('demo', fn () => Category::factory()->create());

        $this->putJson("/api/v1/modifier-groups/{$group['id']}/attachments", ['product_ids' => [], 'category_ids' => [$demoCategory->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['category_ids']);
    }

    public function test_a_group_without_any_active_option_cannot_be_attached(): void
    {
        $group = ModifierGroup::factory()->create();
        $category = Category::factory()->create();

        $this->putJson("/api/v1/modifier-groups/{$group->id}/attachments", ['product_ids' => [], 'category_ids' => [$category->id]])
            ->assertStatus(422);
    }

    public function test_an_unused_group_can_be_deleted_and_the_deletion_is_logged(): void
    {
        $group = $this->postJson('/api/v1/modifier-groups', $this->payload())->json();

        $this->deleteJson("/api/v1/modifier-groups/{$group['id']}")->assertNoContent();

        $this->assertDatabaseMissing('modifier_groups', ['id' => $group['id']]);
        $this->assertDatabaseMissing('modifier_options', ['modifier_group_id' => $group['id']]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'deleted', 'entity_type' => 'ModifierGroup', 'entity_id' => $group['id']]);
    }

    public function test_a_group_that_ever_appeared_on_an_order_line_cannot_be_deleted(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $group = $this->postJson('/api/v1/modifier-groups', $this->payload())->json();
        $order = $this->makeOpenOrder($this->makeShift($cashier), $cashier);
        $line = $this->addLineTo($order, $this->makeVariant());
        $line->update(['modifiers' => [['group_id' => $group['id'], 'group' => 'Size', 'option_id' => 1, 'option' => 'Large', 'price_delta' => '5000.00']]]);

        $this->deleteJson("/api/v1/modifier-groups/{$group['id']}")->assertStatus(409);
        $this->assertDatabaseHas('modifier_groups', ['id' => $group['id']]);
    }

    public function test_the_list_returns_groups_with_options_and_attachments(): void
    {
        $this->postJson('/api/v1/modifier-groups', $this->payload(['name' => 'Sugar']));
        $this->postJson('/api/v1/modifier-groups', $this->payload(['name' => 'Size']));

        $res = $this->getJson('/api/v1/modifier-groups')->assertOk();

        $res->assertJsonCount(2, 'data')->assertJsonStructure(['data' => [['id', 'name', 'options', 'product_ids', 'category_ids']]]);
    }

    public function test_groups_of_the_other_data_mode_are_invisible(): void
    {
        ModeGate::runAs('demo', fn () => ModifierGroup::factory()->create(['name' => 'DEMO-ONLY']));
        $this->postJson('/api/v1/modifier-groups', $this->payload(['name' => 'LIVE-ONLY']));

        $names = collect($this->getJson('/api/v1/modifier-groups')->json('data'))->pluck('name');

        $this->assertSame(['LIVE-ONLY'], $names->all());
    }

    public function test_the_menu_listing_exposes_effective_groups_only_when_asked(): void
    {
        $group = $this->postJson('/api/v1/modifier-groups', $this->payload())->json();
        $variant = $this->makeVariant();
        $product = $variant->product;
        $this->putJson("/api/v1/modifier-groups/{$group['id']}/attachments", ['product_ids' => [$product->id], 'category_ids' => []]);

        $plain = $this->getJson('/api/v1/products?with_variants=1')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('modifier_groups', $plain);

        $with = $this->getJson('/api/v1/products?with_variants=1&with_modifiers=1')->assertOk()->json('data.0');
        $this->assertSame('Size', $with['modifier_groups'][0]['name']);
        // 2026-10-31 — opsi juga membawa stok bahannya (`materials`, `portions`) untuk popup POS; bidang tambahan saja.
        $this->assertSame(['id', 'name', 'price_delta', 'materials', 'portions'], array_keys($with['modifier_groups'][0]['options'][0]));
    }

    public function test_the_menu_listing_merges_product_and_category_groups_without_duplicates_and_hides_inactive_ones(): void
    {
        $shared = $this->postJson('/api/v1/modifier-groups', $this->payload(['name' => 'Shared']))->json();
        $catOnly = $this->postJson('/api/v1/modifier-groups', $this->payload(['name' => 'CatOnly']))->json();
        $off = $this->postJson('/api/v1/modifier-groups', $this->payload(['name' => 'Off']))->json();
        $variant = $this->makeVariant();
        $product = $variant->product;
        $category = $product->category;

        $this->putJson("/api/v1/modifier-groups/{$shared['id']}/attachments", ['product_ids' => [$product->id], 'category_ids' => [$category->id]]);
        $this->putJson("/api/v1/modifier-groups/{$catOnly['id']}/attachments", ['product_ids' => [], 'category_ids' => [$category->id]]);
        $this->putJson("/api/v1/modifier-groups/{$off['id']}/attachments", ['product_ids' => [$product->id], 'category_ids' => []]);
        $this->putJson("/api/v1/modifier-groups/{$off['id']}", ['is_active' => false]);

        $names = collect($this->getJson('/api/v1/products?with_variants=1&with_modifiers=1')->json('data.0.modifier_groups'))->pluck('name');

        $this->assertEqualsCanonicalizing(['Shared', 'CatOnly'], $names->all());
    }

    public function test_a_role_without_dining_setup_is_refused(): void
    {
        $group = ModifierGroup::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->getJson('/api/v1/modifier-groups')->assertForbidden();
        $this->postJson('/api/v1/modifier-groups', $this->payload())->assertForbidden();
        $this->putJson("/api/v1/modifier-groups/{$group->id}", ['name' => 'x'])->assertForbidden();
        $this->putJson("/api/v1/modifier-groups/{$group->id}/attachments", ['product_ids' => [], 'category_ids' => []])->assertForbidden();
        $this->deleteJson("/api/v1/modifier-groups/{$group->id}")->assertForbidden();
    }
}
