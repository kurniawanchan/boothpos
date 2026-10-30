<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ModifierGroup;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 086 — sebuah item boleh MELEPAS grup modifier yang diwarisi dari kategorinya (`excluded_group_ids` pada
 * `PUT /products/{id}/modifier-links`). Yang dijaga: pengecualian berlaku di SEMUA pembaca grup kategori (daftar POS,
 * pencarian varian, validasi pesanan), hanya untuk item itu, kategori tidak berubah, tautan item menang, izin ditegakkan
 * server, dan klien yang tak mengirim kuncinya tidak menghapus pengecualian.
 */
class ModifierExclusionTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $owner;

    private ProductVariant $a;

    private ProductVariant $b;

    private ModifierGroup $suhu;

    private ModifierGroup $es;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');

        // Dua item di KATEGORI yang sama (makeVariant membuat kategori baru tiap panggilan).
        $this->a = $this->makeVariant(stock: 50, price: 12000);
        $this->b = $this->makeVariant(stock: 50, price: 15000);
        $this->b->product->update(['category_id' => $this->a->product->category_id]);

        $this->suhu = ModifierGroup::factory()->create(['name' => 'Suhu', 'selection_type' => 'single', 'is_required' => false, 'min_select' => 0, 'max_select' => 1]);
        $this->suhu->options()->create(['name' => 'Panas', 'price_delta' => 0]);
        $this->suhu->options()->create(['name' => 'Dingin', 'price_delta' => 0]);
        $this->suhu->categories()->attach($this->a->product->category_id);

        $this->es = ModifierGroup::factory()->create(['name' => 'Es', 'selection_type' => 'single', 'is_required' => false, 'min_select' => 0, 'max_select' => 1]);
        $this->es->options()->create(['name' => 'Banyak', 'price_delta' => 0]);
    }

    private function save(ProductVariant $v, array $body)
    {
        return $this->putJson("/api/v1/products/{$v->product_id}/modifier-links", array_merge(['links' => []], $body));
    }

    /** @return list<string> nama grup efektif item di daftar POS */
    private function listedGroups(ProductVariant $v): array
    {
        $rows = $this->getJson('/api/v1/products?with_variants=1&with_modifiers=1&per_page=100')->assertOk()->json('data');
        $row = collect($rows)->firstWhere('id', $v->product_id);

        return collect($row['modifier_groups'])->pluck('name')->sort()->values()->all();
    }

    /** @return list<string> nama grup efektif item di pencarian varian */
    private function lookedUpGroups(ProductVariant $v): array
    {
        $rows = $this->getJson('/api/v1/variants/lookup?q='.$v->sku.'&with_modifiers=1')->assertOk()->json('data');
        $row = collect($rows)->firstWhere('variant_id', $v->id);

        return collect($row['modifier_groups'])->pluck('name')->sort()->values()->all();
    }

    public function test_before_any_exclusion_both_items_inherit_the_category_group(): void
    {
        $this->assertSame(['Suhu'], $this->listedGroups($this->a));
        $this->assertSame(['Suhu'], $this->listedGroups($this->b));
    }

    public function test_excluding_a_category_group_removes_it_from_that_item_in_every_reader(): void
    {
        $this->save($this->a, ['excluded_group_ids' => [$this->suhu->id]])->assertOk()->assertJsonPath('applied', true);

        $this->assertSame([], $this->listedGroups($this->a));
        $this->assertSame([], $this->lookedUpGroups($this->a));
        // Halaman Produk: grup efektif tidak memuatnya, dan grup itu terdaftar sebagai dilepas.
        $detail = $this->getJson("/api/v1/products/{$this->a->product_id}")->assertOk();
        $this->assertSame([], collect($detail->json('modifier_links'))->pluck('name')->all());
        $this->assertSame(['Suhu'], collect($detail->json('modifier_excluded'))->pluck('name')->all());
    }

    public function test_other_items_in_the_same_category_and_the_category_itself_are_unchanged(): void
    {
        $this->save($this->a, ['excluded_group_ids' => [$this->suhu->id]])->assertOk();

        $this->assertSame(['Suhu'], $this->listedGroups($this->b));
        $this->assertSame(['Suhu'], $this->lookedUpGroups($this->b));
        $this->assertSame([$this->suhu->id], $this->a->product->category->modifierGroups()->pluck('modifier_groups.id')->all());
        $this->assertSame([], $this->b->product->fresh()->excludedModifierGroups()->pluck('modifier_groups.id')->all());
    }

    public function test_the_products_own_link_to_the_same_group_still_wins_over_the_exclusion(): void
    {
        $this->save($this->a, [
            'links' => [['group_id' => $this->suhu->id, 'is_required' => true, 'min_select' => 1, 'max_select' => 1, 'display_order' => 1]],
            'excluded_group_ids' => [$this->suhu->id],
        ])->assertOk();

        $this->assertSame(['Suhu'], $this->listedGroups($this->a));
    }

    public function test_an_option_of_an_excluded_group_is_refused_on_that_item_but_accepted_on_its_sibling(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->save($this->a, ['excluded_group_ids' => [$this->suhu->id]])->assertOk();
        $panas = $this->suhu->options()->where('name', 'Panas')->value('id');

        $this->actingAs($cashier, 'sanctum');
        $tab = $this->apiOpenTab($this->makeShift($cashier)->id)->json('id');

        $this->apiAddLine($tab, $this->a->id, 1, ['modifier_option_ids' => [$panas]])->assertStatus(422);
        $this->apiAddLine($tab, $this->b->id, 1, ['modifier_option_ids' => [$panas]])->assertCreated()
            ->assertJsonPath('lines.0.modifiers.0.group', 'Suhu');
    }

    public function test_a_tab_line_that_already_carries_the_group_is_not_rewritten(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $panas = $this->suhu->options()->where('name', 'Panas')->value('id');
        $this->actingAs($cashier, 'sanctum');
        $tab = $this->apiOpenTab($this->makeShift($cashier)->id)->json('id');
        $this->apiAddLine($tab, $this->a->id, 1, ['modifier_option_ids' => [$panas]])->assertCreated();

        $this->actingAs($this->owner, 'sanctum');
        $this->save($this->a, ['excluded_group_ids' => [$this->suhu->id]])->assertOk();

        $this->actingAs($cashier, 'sanctum');
        $this->getJson("/api/v1/tabs/{$tab}")->assertOk()
            ->assertJsonPath('lines.0.modifiers.0.group', 'Suhu')->assertJsonPath('lines.0.modifiers.0.option', 'Panas');
    }

    public function test_a_role_without_permission_is_refused_and_nothing_is_written(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->save($this->a, ['excluded_group_ids' => [$this->suhu->id]])->assertStatus(403);

        $this->assertSame(0, $this->a->product->fresh()->excludedModifierGroups()->count());
    }

    public function test_a_request_without_the_key_leaves_existing_exclusions_alone(): void
    {
        $this->save($this->a, ['excluded_group_ids' => [$this->suhu->id]])->assertOk();

        // Klien lama: hanya mengirim tautan, tanpa `excluded_group_ids`.
        $this->save($this->a, ['links' => [['group_id' => $this->es->id, 'is_required' => false, 'min_select' => 0, 'max_select' => 1]]])->assertOk();

        $this->assertSame([$this->suhu->id], $this->a->product->fresh()->excludedModifierGroups()->pluck('modifier_groups.id')->all());
        $this->assertSame(['Es'], $this->listedGroups($this->a));
    }

    public function test_unknown_and_duplicate_group_ids_are_refused_without_changing_anything(): void
    {
        $this->save($this->a, ['excluded_group_ids' => [999999]])->assertStatus(422)->assertJsonValidationErrors('excluded_group_ids.0');
        $this->save($this->a, ['excluded_group_ids' => [$this->suhu->id, $this->suhu->id]])->assertStatus(422)->assertJsonValidationErrors('excluded_group_ids.1');

        $this->assertSame(0, $this->a->product->fresh()->excludedModifierGroups()->count());
        $this->assertSame(['Suhu'], $this->listedGroups($this->a));
    }

    public function test_one_audit_row_records_the_old_and_new_exclusions_without_secrets(): void
    {
        $this->save($this->a, ['excluded_group_ids' => [$this->suhu->id]])->assertOk();

        $log = ActivityLog::where('action', 'modifier_links_updated')->where('entity_id', $this->a->product_id)->latest('id')->firstOrFail();
        $this->assertSame([], $log->old_values['excluded_group_ids']);
        $this->assertSame([$this->suhu->id], $log->new_values['excluded_group_ids']);
        $this->assertSame(1, ActivityLog::where('action', 'modifier_links_updated')->where('entity_id', $this->a->product_id)->count());
    }

    // --- US2: pulihkan -----------------------------------------------------------------------------------------

    public function test_the_detail_page_lists_a_released_group_with_its_rules_and_not_among_the_effective_links(): void
    {
        $this->save($this->a, ['excluded_group_ids' => [$this->suhu->id]])->assertOk();

        $detail = $this->getJson("/api/v1/products/{$this->a->product_id}")->assertOk();

        $this->assertSame([], $detail->json('modifier_links'));
        $row = $detail->json('modifier_excluded.0');
        $this->assertSame($this->suhu->id, $row['group_id']);
        $this->assertSame('category', $row['source']);
        $this->assertSame(['Panas', 'Dingin'], $row['options']);
        // Aturan grup ikut, supaya UI bisa menampilkannya lagi begitu dipulihkan.
        $this->assertFalse($row['is_required']);
        $this->assertSame(1, $row['max_select']);
    }

    public function test_an_empty_list_restores_everything_and_the_group_is_asked_again(): void
    {
        $this->save($this->a, ['excluded_group_ids' => [$this->suhu->id]])->assertOk();
        $this->assertSame([], $this->listedGroups($this->a));

        $this->save($this->a, ['excluded_group_ids' => []])->assertOk();

        $this->assertSame(['Suhu'], $this->listedGroups($this->a));
        $this->assertSame(0, $this->a->product->fresh()->excludedModifierGroups()->count());
        $this->assertSame([], $this->getJson("/api/v1/products/{$this->a->product_id}")->json('modifier_excluded'));
    }

    public function test_a_subset_restores_only_the_groups_that_are_no_longer_listed(): void
    {
        $this->es->categories()->attach($this->a->product->category_id);
        $this->save($this->a, ['excluded_group_ids' => [$this->suhu->id, $this->es->id]])->assertOk();
        $this->assertSame([], $this->listedGroups($this->a));

        $this->save($this->a, ['excluded_group_ids' => [$this->es->id]])->assertOk();

        $this->assertSame(['Suhu'], $this->listedGroups($this->a));
        $this->assertSame([$this->es->id], $this->a->product->fresh()->excludedModifierGroups()->pluck('modifier_groups.id')->all());
    }

    public function test_saving_the_same_exclusion_again_keeps_the_original_released_time(): void
    {
        $this->save($this->a, ['excluded_group_ids' => [$this->suhu->id]])->assertOk();
        $first = \DB::table('modifier_group_product_exclusions')->where('product_id', $this->a->product_id)->value('created_at');
        $this->assertNotNull($first);

        $this->travel(2)->hours();
        $this->save($this->a, ['excluded_group_ids' => [$this->suhu->id]])->assertOk();

        $this->assertSame($first, \DB::table('modifier_group_product_exclusions')->where('product_id', $this->a->product_id)->value('created_at'));
    }

    // --- US3: tepi ----------------------------------------------------------------------------------------------

    public function test_a_group_added_to_the_category_later_still_applies_to_an_item_that_released_another(): void
    {
        $this->save($this->a, ['excluded_group_ids' => [$this->suhu->id]])->assertOk();

        $this->es->categories()->attach($this->a->product->category_id);

        $this->assertSame(['Es'], $this->listedGroups($this->a));
        $this->assertSame(['Es', 'Suhu'], $this->listedGroups($this->b));
    }

    public function test_the_exclusion_follows_the_item_to_another_category_and_the_new_category_adds_its_own_groups(): void
    {
        $this->save($this->a, ['excluded_group_ids' => [$this->suhu->id]])->assertOk();
        $other = \App\Models\Category::factory()->create();
        $this->es->categories()->attach($other->id);
        $this->suhu->categories()->attach($other->id);

        $this->a->product->update(['category_id' => $other->id]);

        // "Suhu" tetap dilepas (pengecualian terikat item + grup), "Es" datang dari kategori baru.
        $this->assertSame(['Es'], $this->listedGroups($this->a));
    }

    public function test_a_released_group_that_is_deactivated_or_deleted_causes_no_error(): void
    {
        $this->save($this->a, ['excluded_group_ids' => [$this->suhu->id]])->assertOk();

        $this->suhu->update(['is_active' => false]);
        $this->assertSame([], $this->listedGroups($this->a));
        $this->getJson("/api/v1/products/{$this->a->product_id}")->assertOk();

        // Hapus lewat jalur sungguhan (opsi ikut dihapus; grup yang pernah dipakai pesanan akan ditolak service).
        $this->suhu->update(['is_active' => true]);
        app(\App\Services\ModifierGroupService::class)->delete($this->suhu, $this->owner);

        $this->assertSame(0, \DB::table('modifier_group_product_exclusions')->where('product_id', $this->a->product_id)->count());
        $this->getJson("/api/v1/products/{$this->a->product_id}")->assertOk()->assertJsonPath('modifier_excluded', []);
        $this->assertSame([], $this->listedGroups($this->a));
    }

    public function test_when_the_category_loses_the_group_nothing_breaks_and_a_relink_is_still_released(): void
    {
        $this->save($this->a, ['excluded_group_ids' => [$this->suhu->id]])->assertOk();
        $categoryId = $this->a->product->category_id;

        $this->suhu->categories()->detach($categoryId);
        $this->getJson("/api/v1/products/{$this->a->product_id}")->assertOk()->assertJsonPath('modifier_excluded', []);
        $this->assertSame([], $this->listedGroups($this->a));

        $this->suhu->categories()->attach($categoryId);
        $this->assertSame([], $this->listedGroups($this->a));
        $this->assertSame(['Suhu'], $this->listedGroups($this->b));
    }

    public function test_with_the_menu_release_on_the_release_is_queued_and_applied_when_published(): void
    {
        \App\Models\Setting::updateOrCreate(['key' => 'menu_release_enabled'], ['value' => '1', 'type' => 'boolean', 'group' => 'menu']);

        $this->save($this->a, ['excluded_group_ids' => [$this->suhu->id]])->assertOk()->assertJsonPath('applied', false);

        // Belum berlaku: diantrekan sebagai menu_change `modifier_link` dengan payload pengecualian.
        $change = \App\Models\MenuChange::query()->where('kind', \App\Models\MenuChange::KIND_MODIFIER_LINK)->latest('id')->firstOrFail();
        $this->assertSame([$this->suhu->id], $change->payload['excluded_group_ids']);
        $this->assertSame(['Suhu'], $this->listedGroups($this->a));

        app(\App\Services\Release\ModifierLinkApplier::class)->apply($change, $this->owner, null);

        $this->assertSame([], $this->listedGroups($this->a));
    }

    public function test_a_queued_change_without_the_exclusion_key_leaves_an_existing_exclusion_alone(): void
    {
        $this->save($this->a, ['excluded_group_ids' => [$this->suhu->id]])->assertOk();
        \App\Models\Setting::updateOrCreate(['key' => 'menu_release_enabled'], ['value' => '1', 'type' => 'boolean', 'group' => 'menu']);

        $this->save($this->a, ['links' => [['group_id' => $this->es->id, 'is_required' => false, 'min_select' => 0, 'max_select' => 1]]])->assertOk()->assertJsonPath('applied', false);
        $change = \App\Models\MenuChange::query()->where('kind', \App\Models\MenuChange::KIND_MODIFIER_LINK)->latest('id')->firstOrFail();
        $this->assertArrayNotHasKey('excluded_group_ids', $change->payload);

        app(\App\Services\Release\ModifierLinkApplier::class)->apply($change, $this->owner, null);

        $this->assertSame([$this->suhu->id], $this->a->product->fresh()->excludedModifierGroups()->pluck('modifier_groups.id')->all());
    }
}

