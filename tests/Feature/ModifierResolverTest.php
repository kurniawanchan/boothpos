<?php

namespace Tests\Feature;

use App\Exceptions\InputRejectedException;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\ProductVariant;
use App\Services\ModifierResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 028 US3 — matriks aturan pemilihan modifier (FR-024). Diuji pada level service:
 * ModifierResolver adalah satu-satunya tempat aturan ini hidup.
 */
class ModifierResolverTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private ProductVariant $variant;
    private ModifierResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->variant = $this->makeVariant(price: 25000);
        $this->resolver = app(ModifierResolver::class);
    }

    /** @return array{0: ModifierGroup, 1: array<int, ModifierOption>} */
    private function group(string $name, string $type, bool $required, ?int $min, ?int $max, array $deltas, bool $attach = true): array
    {
        $group = ModifierGroup::factory()->create([
            'name' => $name, 'selection_type' => $type, 'is_required' => $required,
            'min_select' => $min ?? 0, 'max_select' => $max,
        ]);
        $options = [];
        foreach ($deltas as $i => $delta) {
            $options[] = $group->options()->create(['name' => "$name-$i", 'price_delta' => $delta]);
        }
        if ($attach) {
            $group->products()->attach($this->variant->product_id);
        }

        return [$group, $options];
    }

    private function ids(array $options): array
    {
        return array_map(fn ($o) => $o->id, $options);
    }

    private function assertRejected(array $ids, string $mustMention = ''): void
    {
        try {
            $this->resolver->resolve($this->variant, $ids);
            $this->fail('Seharusnya ditolak');
        } catch (InputRejectedException $e) {
            $this->assertArrayHasKey('modifier_option_ids', $e->errors());
            if ($mustMention !== '') {
                $this->assertStringContainsString($mustMention, $e->errors()['modifier_option_ids'][0]);
            }
        }
    }

    public function test_a_product_without_groups_resolves_to_nothing(): void
    {
        $r = $this->resolver->resolve($this->variant, []);

        $this->assertSame([], $r['snapshot']);
        $this->assertEquals(0, $r['delta']);
    }

    public function test_a_required_single_group_demands_exactly_one_choice(): void
    {
        [$g, $o] = $this->group('Size', 'single', true, 1, 1, [0, 5000]);

        $this->assertRejected([], 'Size');
        $this->assertRejected($this->ids($o), 'Size'); // dua pilihan pada grup tunggal

        $r = $this->resolver->resolve($this->variant, [$o[1]->id]);
        $this->assertEquals(5000, $r['delta']);
        $this->assertSame('Size', $r['snapshot'][0]['group']);
        $this->assertSame('Size-1', $r['snapshot'][0]['option']);
        $this->assertSame('5000.00', $r['snapshot'][0]['price_delta']);
        $this->assertSame($g->id, $r['snapshot'][0]['group_id']);
        $this->assertSame($o[1]->id, $r['snapshot'][0]['option_id']);
    }

    public function test_an_optional_single_group_may_be_skipped(): void
    {
        $this->group('Sugar', 'single', false, 0, 1, [0, 0]);

        $this->assertSame([], $this->resolver->resolve($this->variant, [])['snapshot']);
    }

    public function test_a_multiple_choice_group_enforces_min_and_max(): void
    {
        [, $o] = $this->group('Add-ons', 'multiple', true, 1, 2, [8000, 10000, 3000]);

        $this->assertRejected([], 'Add-ons');
        $this->assertRejected($this->ids($o), 'Add-ons'); // 3 > max 2

        $this->assertCount(1, $this->resolver->resolve($this->variant, [$o[0]->id])['snapshot']);
        $r = $this->resolver->resolve($this->variant, [$o[0]->id, $o[1]->id]);
        $this->assertCount(2, $r['snapshot']);
        $this->assertEquals(18000, $r['delta']);
    }

    public function test_an_unbounded_multiple_group_has_no_upper_limit(): void
    {
        [, $o] = $this->group('Toppings', 'multiple', false, 0, null, [1000, 1000, 1000, 1000]);

        $this->assertCount(4, $this->resolver->resolve($this->variant, $this->ids($o))['snapshot']);
    }

    public function test_the_message_names_the_group_and_the_allowed_range(): void
    {
        $this->group('Add-ons', 'multiple', true, 1, 2, [1000]);

        $this->assertRejected([], 'Add-ons');
        $this->assertRejected([], '1');
    }

    public function test_an_option_of_a_group_that_is_not_effective_for_the_product_is_rejected(): void
    {
        [, $foreign] = $this->group('Foreign', 'single', false, 0, 1, [1000], attach: false);

        $this->assertRejected([$foreign[0]->id]);
    }

    public function test_a_group_attached_to_the_products_category_is_effective(): void
    {
        [$g, $o] = $this->group('CatGroup', 'single', true, 1, 1, [2000], attach: false);
        $g->categories()->attach($this->variant->product->category_id);

        $this->assertEquals(2000, $this->resolver->resolve($this->variant, [$o[0]->id])['delta']);
    }

    public function test_a_group_on_both_product_and_category_counts_once(): void
    {
        [$g, $o] = $this->group('Both', 'single', true, 1, 1, [1000]);
        $g->categories()->attach($this->variant->product->category_id);

        $r = $this->resolver->resolve($this->variant->fresh('product'), [$o[0]->id]);
        $this->assertCount(1, $r['snapshot']);
        $this->assertCount(1, $this->resolver->effectiveGroups($this->variant->product));
    }

    public function test_inactive_options_and_groups_are_not_accepted_and_do_not_count_as_required(): void
    {
        [$g, $o] = $this->group('Size', 'single', true, 1, 1, [0, 5000]);
        $o[1]->update(['is_active' => false]);
        $this->assertRejected([$o[1]->id]);

        $g->update(['is_active' => false]);
        // Grup nonaktif dilewati sepenuhnya: tidak diminta, tidak diterima.
        $this->assertSame([], $this->resolver->resolve($this->variant, [])['snapshot']);
        $this->assertRejected([$o[0]->id]);
    }

    public function test_an_unknown_option_id_is_rejected(): void
    {
        $this->group('Size', 'single', false, 0, 1, [0]);

        $this->assertRejected([999999]);
    }

    public function test_duplicate_ids_count_once(): void
    {
        [, $o] = $this->group('Size', 'single', true, 1, 1, [1500]);

        $r = $this->resolver->resolve($this->variant, [$o[0]->id, $o[0]->id]);
        $this->assertCount(1, $r['snapshot']);
        $this->assertEquals(1500, $r['delta']);
    }

    public function test_negative_differences_reduce_the_price_but_never_below_zero(): void
    {
        [, $o] = $this->group('Promo', 'single', true, 1, 1, [-5000, -30000]);

        $this->assertEquals(-5000, $this->resolver->resolve($this->variant, [$o[0]->id])['delta']);
        $this->assertRejected([$o[1]->id]); // 25.000 - 30.000 < 0
    }

    public function test_the_snapshot_is_ordered_by_group_display_order(): void
    {
        [$a, $ao] = $this->group('Zzz', 'single', true, 1, 1, [0]);
        [$b, $bo] = $this->group('Aaa', 'single', true, 1, 1, [0]);
        $a->update(['display_order' => 2]);
        $b->update(['display_order' => 1]);

        $r = $this->resolver->resolve($this->variant, [$ao[0]->id, $bo[0]->id]);

        $this->assertSame(['Aaa', 'Zzz'], array_column($r['snapshot'], 'group'));
    }
}
