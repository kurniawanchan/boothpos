<?php

namespace Tests\Feature;

use App\Models\ItemAvailabilityMark;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\PriceHistory;
use App\Models\ReasonCode;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Station;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 043-products-page-redesign (T037) — GET /products/{id} (halaman item) dan GET /products/{id}/price-history.
 */
class ProductDetailTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    private function group(string $name, array $over = []): ModifierGroup
    {
        $g = ModifierGroup::create(array_merge(['name' => $name], $over));
        ModifierOption::create(['modifier_group_id' => $g->id, 'name' => 'Normal', 'price_delta' => 0]);
        ModifierOption::create(['modifier_group_id' => $g->id, 'name' => 'Less sugar', 'price_delta' => 0]);

        return $g;
    }

    public function test_the_detail_carries_facts_station_variants_modifier_links_availability_and_can(): void
    {
        $station = Station::create(['name' => 'Bar', 'display_order' => 1, 'is_active' => true]);
        $variant = $this->makeVariant(stock: 3, price: 8000, variantOverrides: ['cost_price' => 3000]);
        $p = $variant->product;
        $p->update(['name' => 'Kopi Tubruk', 'short_name' => 'Tubruk', 'tax_category' => 'restaurant_food_beverage', 'prep_minutes' => 3, 'station_id' => $station->id]);
        $g = $this->group('Gula', ['is_required' => false, 'min_select' => 0, 'max_select' => 1]);
        $p->modifierGroups()->attach($g->id, ['has_rules' => true, 'is_required' => true, 'min_select' => 1, 'max_select' => 2, 'display_order' => 1]);

        $json = $this->getJson("/api/v1/products/{$p->id}")->assertOk()->json();

        $this->assertSame('Tubruk', $json['short_name']);
        $this->assertSame(3, $json['prep_minutes']);
        $this->assertSame('active', $json['status']);
        $this->assertSame(['id' => $station->id, 'name' => 'Bar', 'source' => 'product'], $json['station']);
        $this->assertSame('8000.00', $json['variants'][0]['sell_price']);
        $this->assertSame('3000.00', $json['variants'][0]['cost_price']);
        $link = $json['modifier_links'][0];
        $this->assertSame(['Gula', 'product', true, 1, 2], [$link['name'], $link['source'], $link['is_required'], $link['min_select'], $link['max_select']]);   // aturan TAUTAN menimpa grup
        $this->assertSame(['Normal', 'Less sugar'], $link['options']);
        $this->assertSame('available', $json['availability'][0]['state']);
        $this->assertNull($json['release']);
        $this->assertTrue($json['can']['edit'] && $json['can']['mark_86'] && $json['can']['discontinue'] && $json['can']['bulk_price']);
    }

    public function test_a_group_from_the_category_shows_with_its_own_rule_and_a_product_link_wins_over_it(): void
    {
        $variant = $this->makeVariant(stock: 3);
        $p = $variant->product;
        $catOnly = $this->group('Level pedas', ['is_required' => true, 'min_select' => 1, 'max_select' => 1]);
        $both = $this->group('Gula', ['max_select' => 3]);
        $p->category->modifierGroups()->attach([$catOnly->id, $both->id]);
        $p->modifierGroups()->attach($both->id, ['has_rules' => true, 'is_required' => false, 'min_select' => 0, 'max_select' => 1, 'display_order' => 0]);

        $links = collect($this->getJson("/api/v1/products/{$p->id}")->json('modifier_links'))->keyBy('name');

        $this->assertSame('category', $links['Level pedas']['source']);
        $this->assertTrue($links['Level pedas']['is_required']);
        $this->assertSame('product', $links['Gula']['source']);
        $this->assertSame(1, $links['Gula']['max_select']);
        $this->assertCount(2, $links);
    }

    public function test_sold_out_state_release_info_and_a_station_from_the_category(): void
    {
        $station = Station::create(['name' => 'Dapur', 'display_order' => 1, 'is_active' => true]);
        $variant = $this->makeVariant(stock: 3);
        $variant->product->category->update(['station_id' => $station->id]);
        ItemAvailabilityMark::create(['product_id' => $variant->product_id, 'reason_code_id' => ReasonCode::where('category', 'sold_out')->value('id'), 'marked_by' => auth()->id(), 'marked_at' => now(), 'expires_at' => now()->addHour(), 'note' => 'habis']);
        Setting::updateOrCreate(['key' => 'menu_release_enabled'], ['value' => 'true', 'type' => 'boolean']);

        $json = $this->getJson("/api/v1/products/{$variant->product_id}")->json();

        $this->assertSame('category', $json['station']['source']);
        $this->assertSame('sold_out', $json['availability'][0]['state']);
        $this->assertSame('habis', $json['availability'][0]['note']);
        $this->assertSame(['version' => 0, 'outlets_active' => 1], $json['release']);
    }

    public function test_a_cashier_sees_no_cost_and_no_actions(): void
    {
        $variant = $this->makeVariant(stock: 3, price: 8000, variantOverrides: ['cost_price' => 3000]);
        $role = Role::factory()->create(['menu_keys' => ['pos']]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]), 'sanctum');

        $json = $this->getJson("/api/v1/products/{$variant->product_id}")->assertOk()->json();

        $this->assertArrayNotHasKey('cost_price', $json['variants'][0]);
        $this->assertFalse($json['can']['mark_86'] || $json['can']['discontinue'] || $json['can']['bulk_price'] || $json['can']['edit']);
    }

    public function test_price_history_is_newest_first_ends_with_creation_and_is_paginated(): void
    {
        $variant = $this->makeVariant(stock: 1, price: 7000);
        $p = $variant->product;
        PriceHistory::where('variant_id', $variant->id)->delete();   // baris 'created' otomatis dari hook varian; tes ini menyusun riwayatnya sendiri
        $mk = fn (string $at, $old, $new, string $source) => PriceHistory::create(['variant_id' => $variant->id, 'old_price' => $old, 'new_price' => $new, 'source' => $source, 'effective_at' => $at, 'user_id' => auth()->id()]);
        $mk('2026-03-15 09:00:00', null, 7000, 'created');
        $mk('2026-07-02 09:00:00', 7000, 7500, 'edit');
        $mk('2026-10-01 09:00:00', 7500, 8000, 'bulk');

        $res = $this->getJson("/api/v1/products/{$p->id}/price-history")->assertOk();

        $this->assertSame(['bulk', 'edit', 'created'], array_column($res->json('data'), 'source'));
        $this->assertSame('8000.00', $res->json('data.0.new_price'));
        $this->assertNull($res->json('data.2.old_price'));
        $this->assertSame(3, $res->json('meta.total'));
        $this->assertCount(1, $this->getJson("/api/v1/products/{$p->id}/price-history?per_page=1&page=3")->json('data'));
    }

    public function test_another_data_mode_is_not_found(): void
    {
        $variant = $this->makeVariant(stock: 1);
        ModeGate::runAs(ModeGate::DEMO, fn () => $this->getJson("/api/v1/products/{$variant->product_id}")->assertNotFound());
    }
}
