<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\MenuChange;
use App\Models\ModifierGroup;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 043-products-page-redesign (T070) — PUT /products/{id}/modifier-links: aturan PER ITEM (wajib/min/maks/urutan),
 * validasi, audit, mode rilis, dan bahwa resolver memakai aturan tautan di atas aturan grup.
 */
class ModifierLinkTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $owner;
    private $variant;
    private ModifierGroup $sugar;
    private ModifierGroup $topping;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
        $this->variant = $this->makeVariant(stock: 50, price: 20000);

        $this->sugar = ModifierGroup::factory()->create(['name' => 'Gula', 'selection_type' => 'single', 'is_required' => false, 'min_select' => 0, 'max_select' => 1]);
        $this->sugar->options()->create(['name' => 'Normal', 'price_delta' => 0]);
        $this->topping = ModifierGroup::factory()->create(['name' => 'Topping', 'selection_type' => 'multiple', 'is_required' => false, 'min_select' => 0, 'max_select' => 5]);
        $this->topping->options()->create(['name' => 'Boba', 'price_delta' => 4000]);
    }

    private function saveLinks(array $links)
    {
        return $this->putJson("/api/v1/products/{$this->variant->product_id}/modifier-links", ['links' => $links]);
    }

    public function test_replacing_links_writes_the_per_item_rules_and_an_audit_row(): void
    {
        $this->saveLinks([
            ['group_id' => $this->sugar->id, 'is_required' => true, 'min_select' => 1, 'max_select' => 1, 'display_order' => 1],
            ['group_id' => $this->topping->id, 'is_required' => false, 'min_select' => 0, 'max_select' => null, 'display_order' => 2],
        ])->assertOk()->assertJsonPath('applied', true);

        $groups = $this->variant->product->fresh()->modifierGroups()->get()->keyBy('id');
        $this->assertTrue((bool) $groups[$this->sugar->id]->pivot->has_rules);
        $this->assertTrue((bool) $groups[$this->sugar->id]->pivot->is_required);
        $this->assertNull($groups[$this->topping->id]->pivot->max_select);   // kosong = tanpa batas
        $this->assertTrue(ActivityLog::where('action', 'modifier_links_updated')->exists());
    }

    public function test_links_missing_from_the_request_are_detached(): void
    {
        $this->variant->product->modifierGroups()->attach($this->topping->id);

        $this->saveLinks([['group_id' => $this->sugar->id, 'is_required' => false, 'min_select' => 0, 'max_select' => 1]])->assertOk();

        $this->assertSame([$this->sugar->id], $this->variant->product->fresh()->modifierGroups()->pluck('modifier_groups.id')->all());
    }

    public function test_invalid_rules_are_rejected_without_changing_anything(): void
    {
        $this->variant->product->modifierGroups()->attach($this->topping->id);

        $this->saveLinks([['group_id' => $this->sugar->id, 'min_select' => 3, 'max_select' => 2]])->assertStatus(422)->assertJsonValidationErrors(['links.0.max_select']);
        $this->saveLinks([['group_id' => $this->sugar->id, 'min_select' => -1]])->assertStatus(422)->assertJsonValidationErrors(['links.0.min_select']);
        $this->saveLinks([['group_id' => 999999]])->assertStatus(422)->assertJsonValidationErrors(['links.0.group_id']);
        $this->saveLinks([['group_id' => $this->sugar->id], ['group_id' => $this->sugar->id]])->assertStatus(422)->assertJsonValidationErrors(['links.1.group_id']);

        $this->assertSame([$this->topping->id], $this->variant->product->fresh()->modifierGroups()->pluck('modifier_groups.id')->all());
    }

    public function test_the_item_rule_overrides_the_group_rule_when_ordering(): void
    {
        $tab = $this->apiOpenTab($this->makeShift($this->owner)->id)->json('id');
        $boba = $this->topping->options()->first();

        // Aturan grup: opsional. Aturan item: wajib minimal 1 → tanpa pilihan harus ditolak.
        $this->saveLinks([['group_id' => $this->topping->id, 'is_required' => true, 'min_select' => 1, 'max_select' => 2]])->assertOk();

        $this->apiAddLine($tab, $this->variant->id, 1, ['modifier_option_ids' => []])->assertStatus(422)->assertJsonValidationErrors(['modifier_option_ids']);
        $this->apiAddLine($tab, $this->variant->id, 1, ['modifier_option_ids' => [$boba->id]])->assertCreated();
    }

    public function test_an_empty_list_removes_every_link(): void
    {
        $this->variant->product->modifierGroups()->attach($this->sugar->id);

        $this->saveLinks([])->assertOk();

        $this->assertSame(0, $this->variant->product->fresh()->modifierGroups()->count());
    }

    public function test_a_products_menu_user_can_list_groups_but_not_edit_them(): void
    {
        $role = \App\Models\Role::factory()->create(['menu_keys' => ['products']]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]), 'sanctum');

        $this->getJson('/api/v1/modifier-groups')->assertOk()->assertJsonPath('data.0.name', 'Gula');
        $this->postJson('/api/v1/modifier-groups', ['name' => 'X'])->assertForbidden();
    }

    public function test_the_features_payload_carries_the_approval_threshold_and_rounding_default(): void
    {
        $this->getJson('/api/v1/settings/features')->assertOk()
            ->assertJsonPath('price_approval_threshold_pct', 15)->assertJsonPath('price_rounding_default', '500');
    }

    public function test_a_cashier_cannot_change_links(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->saveLinks([])->assertForbidden();
    }

    public function test_with_releases_on_the_change_is_queued_not_applied(): void
    {
        Setting::updateOrCreate(['key' => 'menu_release_enabled'], ['value' => 'true', 'type' => 'boolean']);

        $res = $this->saveLinks([['group_id' => $this->sugar->id, 'is_required' => true, 'min_select' => 1, 'max_select' => 1]])
            ->assertOk()->assertJsonPath('applied', false);

        $this->assertSame(0, $this->variant->product->fresh()->modifierGroups()->count());
        $this->assertSame(MenuChange::KIND_MODIFIER_LINK, MenuChange::findOrFail($res->json('change.id'))->kind);
    }
}
