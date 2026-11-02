<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\ModifierResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MaterialReportFixtures;
use Tests\TestCase;

/**
 * 088 (T003) — pembaca BALIK pewarisan grup modifier: "produk mana yang memakai grup ini?". Harus sama dengan aturan
 * `inheritedGroups()`/`effectiveGroups()` (086): tautan produk ∪ produk kategori bertautan, tanpa produk yang melepas
 * grup warisan; tautan produk langsung menang atas pengecualian.
 */
class ModifierResolverProductsUsingGroupsTest extends TestCase
{
    use MaterialReportFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMaterialReportWorld();
    }

    private function names(array $map, int $groupId): array
    {
        return collect($map[$groupId] ?? [])->pluck('name')->sort()->values()->all();
    }

    public function test_direct_and_category_links_without_opted_out_products(): void
    {
        $map = app(ModifierResolver::class)->productsUsingGroups([$this->topping()->id]);

        // Indomie Kuah + Nasi Goreng (langsung), Mie Goreng (kategori); Mie Rebus melepas grup warisan.
        $this->assertSame(['Indomie Kuah', 'Mie Goreng', 'Nasi Goreng'], $this->names($map, $this->topping()->id));
    }

    public function test_a_direct_link_wins_over_an_exclusion(): void
    {
        $this->mieRebus->modifierGroups()->attach($this->topping()->id);

        $map = app(ModifierResolver::class)->productsUsingGroups([$this->topping()->id]);
        $this->assertContains('Mie Rebus', $this->names($map, $this->topping()->id));
    }

    public function test_it_matches_the_forward_rule_for_every_product(): void
    {
        $groupId = $this->topping()->id;
        $map = app(ModifierResolver::class)->productsUsingGroups([$groupId]);
        $reverse = collect($map[$groupId] ?? [])->pluck('product_id')->sort()->values()->all();

        $forward = Product::all()->filter(fn (Product $p) => app(ModifierResolver::class)->effectiveGroups($p)->pluck('id')->contains($groupId)
            || $p->modifierGroups()->whereKey($groupId)->exists())->pluck('id')->sort()->values()->all();

        $this->assertSame($forward, $reverse);
    }

    public function test_inactive_products_and_unknown_groups_are_left_out(): void
    {
        $this->mieGoreng->update(['is_active' => false]);

        $map = app(ModifierResolver::class)->productsUsingGroups([$this->topping()->id, 999999]);
        $this->assertNotContains('Mie Goreng', $this->names($map, $this->topping()->id));
        $this->assertArrayNotHasKey(999999, $map);
    }
}
