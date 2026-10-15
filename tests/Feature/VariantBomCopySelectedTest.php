<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Artist;
use App\Models\ProductVariant;
use App\Models\ProductVariantBomLine;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\BuildsBomFixtures;
use Tests\TestCase;

/**
 * 037-variant-drawer-bom-ui (US3) — POST /variants/{v}/bom/copy-out dengan mode=selected:
 * salin BOM ke varian PILIHAN pengguna saja. Memakai VariantBomService::copy() apa adanya
 * (semua-atau-tidak-sama-sekali, produk sama, konfirmasi ganti, tidak pernah menandai selesai).
 */
class VariantBomCopySelectedTest extends TestCase
{
    use BuildsBomFixtures, RefreshDatabase;

    private User $owner;

    private Artist $seller;

    /** @var ProductVariant[] [0] = sumber (2 baris BOM), [1..5] = target */
    private array $v;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
        $this->seller = Artist::factory()->create();

        $source = $this->variantFor($this->seller);
        $this->v = [$source];
        foreach (['B', 'C', 'D', 'E', 'F'] as $suffix) {
            $this->v[] = $source->product->variants()->create(['sku' => $source->sku.$suffix, 'variant_name' => $suffix, 'sell_price' => 20000, 'cost_price' => 0, 'current_stock' => 0]);
        }

        [$chain] = $this->po($this->seller, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 500]]);
        [$ring] = $this->po($this->seller, 'ordered', [['type' => 'material', 'name' => 'Ring', 'price' => 300]]);
        $this->addItems($source, [['purchase_order_item_id' => $chain->id, 'qty' => 2], ['purchase_order_item_id' => $ring->id]])->assertCreated();
    }

    private function copySelected(array $ids, array $extra = [], ?ProductVariant $source = null)
    {
        return $this->postJson('/api/v1/variants/'.($source ?? $this->v[0])->id.'/bom/copy-out', ['mode' => 'selected', 'variant_ids' => $ids] + $extra);
    }

    private function rowCount(ProductVariant $variant): int
    {
        return ProductVariantBomLine::where('product_variant_id', $variant->id)->count();
    }

    public function test_it_copies_to_exactly_the_chosen_variants_and_touches_no_other(): void
    {
        $response = $this->copySelected([$this->v[1]->id, $this->v[3]->id, $this->v[5]->id])->assertOk();

        $this->assertCount(3, $response->json('results'));
        $this->assertEqualsCanonicalizing([$this->v[1]->id, $this->v[3]->id, $this->v[5]->id], collect($response->json('results'))->pluck('variant_id')->all());
        foreach ([1, 3, 5] as $i) {
            $this->assertSame(2, $this->rowCount($this->v[$i]));
        }
        foreach ([2, 4] as $i) {
            $this->assertSame(0, $this->rowCount($this->v[$i]), 'an unchosen variant is never touched');
        }
        $this->assertSame(3, ActivityLog::where('action', 'bom_copied')->count(), 'one audit row per chosen variant');
    }

    public function test_chosen_variants_that_already_have_rows_need_confirmation_naming_them(): void
    {
        $this->copySelected([$this->v[1]->id])->assertOk(); // v[1] now has rows

        $needs = $this->copySelected([$this->v[1]->id, $this->v[2]->id])->assertStatus(409)
            ->assertJsonPath('code', 'requires_confirmation');
        $this->assertSame([$this->v[1]->id], collect($needs->json('variants'))->pluck('id')->all());
        $this->assertSame(0, $this->rowCount($this->v[2]), 'nothing was copied while confirmation is pending');

        $this->copySelected([$this->v[1]->id, $this->v[2]->id], ['confirm_replace' => true])->assertOk();
        $this->assertSame(2, $this->rowCount($this->v[2]));
        $this->assertSame(2, $this->rowCount($this->v[1]), 'replaced, not appended');
    }

    public function test_a_complete_target_is_reopened_and_a_copy_never_completes_anyone(): void
    {
        $this->copySelected([$this->v[1]->id])->assertOk();
        $this->postJson("/api/v1/variants/{$this->v[1]->id}/bom/complete")->assertOk();

        $this->copySelected([$this->v[1]->id, $this->v[2]->id], ['confirm_replace' => true])->assertOk()
            ->assertJsonPath('results.0.reopened', true);

        $this->assertFalse((bool) $this->v[1]->fresh()->bom_complete);
        $this->assertFalse((bool) $this->v[2]->fresh()->bom_complete);
    }

    public function test_invalid_selections_are_refused_and_nothing_is_copied(): void
    {
        $otherProduct = $this->variantFor($this->seller);

        $this->copySelected([$this->v[0]->id])->assertStatus(422);                       // la source elle-même
        $this->copySelected([$this->v[1]->id, $otherProduct->id])->assertStatus(422);    // produk lain
        $this->copySelected([$this->v[1]->id, 999999])->assertStatus(422);               // tidak ada
        $this->copySelected([])->assertStatus(422)->assertJsonValidationErrors('variant_ids');
        $this->copySelected([$this->v[1]->id, $this->v[1]->id])->assertStatus(422);      // duplikat
        $this->postJson("/api/v1/variants/{$this->v[0]->id}/bom/copy-out", ['mode' => 'selected'])->assertStatus(422)->assertJsonValidationErrors('variant_ids');

        $this->assertSame(0, $this->rowCount($this->v[1]));
        $this->assertSame(0, $this->rowCount($otherProduct));
    }

    public function test_a_source_without_rows_cannot_be_copied(): void
    {
        $this->copySelected([$this->v[2]->id], [], $this->v[1])->assertStatus(422);
    }

    public function test_the_other_modes_ignore_variant_ids_and_still_work(): void
    {
        $this->postJson("/api/v1/variants/{$this->v[0]->id}/bom/copy-out", ['mode' => 'next', 'variant_ids' => [$this->v[5]->id]])->assertOk()
            ->assertJsonPath('results.0.variant_id', $this->v[1]->id);
        $this->assertSame(0, $this->rowCount($this->v[5]));
    }

    public function test_it_needs_both_the_products_and_purchase_orders_menus(): void
    {
        $productsOnly = User::factory()->create(['role_id' => Role::factory()->create(['name' => 'Staf Produk', 'menu_keys' => ['products']])->id]);
        $this->actingAs($productsOnly, 'sanctum');

        $this->copySelected([$this->v[1]->id])->assertForbidden();
        $this->assertSame(0, $this->rowCount($this->v[1]));
    }

    public function test_a_failure_midway_copies_to_none_of_the_chosen_variants(): void
    {
        $calls = 0;
        // 2 baris per target: kegagalan pada pembuatan baris ke-3 = target KEDUA gagal setelah target pertama selesai.
        Event::listen('eloquent.creating: '.ProductVariantBomLine::class, function () use (&$calls) {
            if (++$calls === 3) {
                throw new \RuntimeException('simulasi gagal di target kedua');
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->copySelected([$this->v[1]->id, $this->v[2]->id]);
            $this->fail('seharusnya melempar');
        } catch (\RuntimeException) {
            // diharapkan
        } finally {
            Event::forget('eloquent.creating: '.ProductVariantBomLine::class);
        }

        $this->assertSame(0, $this->rowCount($this->v[1]));
        $this->assertSame(0, $this->rowCount($this->v[2]));
        $this->assertSame(0, ActivityLog::where('action', 'bom_copied')->count());
    }
}
