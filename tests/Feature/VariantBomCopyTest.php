<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Artist;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantBomLine;
use App\Models\Role;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBomFixtures;
use Tests\TestCase;

/**
 * 034-seller-po-bom (US3) — menyalin BOM antar varian satu produk:
 * dari varian lain, ke varian berikutnya, ke semua varian. Salinan
 * menggandakan baris (snapshot biaya ikut), sesudahnya tiap varian
 * berdiri sendiri.
 */
class VariantBomCopyTest extends TestCase
{
    use BuildsBomFixtures, RefreshDatabase;

    private User $owner;

    private Artist $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
        $this->seller = Artist::factory()->create();
    }

    /** Produk dengan tiga varian (Red/Blue/Green); Red punya BOM contoh (500 + 300 bahan, 1.000 jasa). */
    private function product(): array
    {
        $red = $this->variantFor($this->seller);
        $product = $red->product;
        $blue = $product->variants()->create(['sku' => $red->sku.'B', 'variant_name' => 'Blue', 'sell_price' => 20000, 'cost_price' => 0, 'current_stock' => 0]);
        $green = $product->variants()->create(['sku' => $red->sku.'G', 'variant_name' => 'Green', 'sell_price' => 20000, 'cost_price' => 0, 'current_stock' => 0]);

        [$chain] = $this->po($this->seller, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 500]], 'Vendor X', null, 'PO-C1');
        [$ring] = $this->po($this->seller, 'ordered', [['type' => 'material', 'name' => 'Keychain Ring', 'price' => 300]]);
        [$assembly] = $this->po($this->seller, 'received', [['type' => 'service', 'name' => 'Assembly', 'price' => 1000]], 'Vendor Y');
        $this->addItems($red, [
            ['purchase_order_item_id' => $chain->id, 'qty' => 2], ['purchase_order_item_id' => $ring->id], ['purchase_order_item_id' => $assembly->id],
        ])->assertCreated();
        $red->bomLines()->first()->update(['notes' => 'dua buah']);

        return [$red, $blue, $green];
    }

    private function copyFrom(ProductVariant $target, ProductVariant $source, array $extra = [])
    {
        return $this->postJson("/api/v1/variants/{$target->id}/bom/copy", ['mode' => 'from', 'source_variant_id' => $source->id] + $extra);
    }

    private function copyOut(ProductVariant $source, string $mode, array $extra = [])
    {
        return $this->postJson("/api/v1/variants/{$source->id}/bom/copy-out", ['mode' => $mode] + $extra);
    }

    private function rows(ProductVariant $variant): array
    {
        return $this->getJson("/api/v1/variants/{$variant->id}/bom")->assertOk()->json('data');
    }

    // ===================================================================

    public function test_copy_from_another_variant_duplicates_rows_with_quantities_notes_sources_and_costs(): void
    {
        [$red, $blue] = $this->product();

        $this->copyFrom($blue, $red)->assertOk()
            ->assertJsonPath('summary.bom_cost', '2300.00')
            ->assertJsonPath('results.0.status', 'copied')->assertJsonPath('results.0.rows', 3);

        $source = collect($this->rows($red))->keyBy('item_name');
        $copy = collect($this->rows($blue))->keyBy('item_name');
        $this->assertCount(3, $copy);
        foreach (['Ball Chain', 'Keychain Ring', 'Assembly'] as $name) {
            foreach (['purchase_order_item_id', 'po_number', 'vendor_name', 'unit_cost', 'qty_needed', 'line_type', 'notes'] as $field) {
                $this->assertSame($source[$name][$field], $copy[$name][$field], "{$name}.{$field}");
            }
            $this->assertNotSame($source[$name]['id'], $copy[$name]['id'], 'a copy is a NEW row');
        }
    }

    public function test_copy_to_next_variant_and_to_all_variants(): void
    {
        [$red, $blue, $green] = $this->product();

        $this->copyOut($red, 'next')->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('results.0.variant_id', $blue->id);
        $this->assertCount(3, $this->rows($blue));
        $this->assertCount(0, $this->rows($green));

        $this->copyOut($red, 'all', ['confirm_replace' => true])->assertOk()->assertJsonCount(2, 'results');
        $this->assertCount(3, $this->rows($blue));
        $this->assertCount(3, $this->rows($green));
    }

    public function test_the_next_variant_after_the_last_one_does_not_exist(): void
    {
        [, , $green] = $this->product();
        $this->copyOut($green, 'next')->assertStatus(422);

        $alone = $this->variantFor($this->seller);
        $this->copyOut($alone, 'all')->assertStatus(422);
    }

    public function test_variants_stay_independent_after_a_copy(): void
    {
        [$red, $blue, $green] = $this->product();
        $this->copyOut($red, 'all')->assertOk();

        $blueRows = $this->rows($blue);
        $this->putJson('/api/v1/bom/'.$blueRows[0]['id'], ['qty_needed' => 9])->assertOk();
        $this->deleteJson('/api/v1/bom/'.$blueRows[1]['id'])->assertOk();

        $edited = $blueRows[0]['item_name'];
        $this->assertSame('9.0000', collect($this->rows($blue))->firstWhere('item_name', $edited)['qty_needed']);
        $this->assertSame('2.0000', collect($this->rows($red))->firstWhere('item_name', 'Ball Chain')['qty_needed'], 'the source row is untouched');
        $this->assertCount(3, $this->rows($red));
        $this->assertCount(3, $this->rows($green));
        $this->assertCount(2, $this->rows($blue));
        $this->assertSame(
            collect($this->rows($green))->pluck('qty_needed', 'item_name')->all(),
            collect($this->rows($red))->pluck('qty_needed', 'item_name')->all(),
        );
    }

    public function test_a_target_that_already_has_rows_needs_explicit_confirmation(): void
    {
        [$red, $blue, $green] = $this->product();
        [$extra] = $this->po($this->seller, 'ordered', [['type' => 'material', 'name' => 'Charm', 'price' => 700]]);
        $this->addItems($blue, [['purchase_order_item_id' => $extra->id]])->assertCreated();

        $this->copyOut($red, 'all')->assertStatus(409)
            ->assertJsonPath('code', 'requires_confirmation')->assertJsonPath('requires_confirmation', true)
            ->assertJsonPath('variants.0.id', $blue->id)->assertJsonPath('variants.0.rows', 1);
        $this->assertCount(1, $this->rows($blue), 'nothing changed');
        $this->assertCount(0, $this->rows($green), 'all-or-nothing: the unaffected target was not copied either');

        $this->copyOut($red, 'all', ['confirm_replace' => true])->assertOk();
        $this->assertCount(3, $this->rows($blue));
        $this->assertNull(collect($this->rows($blue))->firstWhere('item_name', 'Charm'));
    }

    public function test_copy_is_refused_across_products_onto_itself_and_from_an_empty_bom(): void
    {
        [$red, $blue] = $this->product();
        $otherProduct = $this->variantFor($this->seller);

        $this->copyFrom($otherProduct, $red)->assertStatus(422);
        $this->copyFrom($red, $red)->assertStatus(422);
        $this->copyFrom($red, $otherProduct)->assertStatus(422);       // sumber dari produk lain (dan tanpa BOM)
        $emptySource = $this->variantFor($this->seller);
        $sibling = $emptySource->product->variants()->create(['sku' => $emptySource->sku.'S', 'variant_name' => 'Sib', 'sell_price' => 1, 'cost_price' => 0, 'current_stock' => 0]);
        $this->copyFrom($sibling, $emptySource)->assertStatus(422);
        $this->assertCount(0, $this->rows($otherProduct));
    }

    public function test_a_copy_never_completes_the_target_and_reopens_a_complete_target_keeping_its_cost_price(): void
    {
        [$red, $blue] = $this->product();
        [$charm] = $this->po($this->seller, 'ordered', [['type' => 'material', 'name' => 'Charm', 'price' => 700]]);
        $this->addItems($blue, [['purchase_order_item_id' => $charm->id]])->assertCreated();
        $this->postJson("/api/v1/variants/{$blue->id}/bom/complete")->assertOk();
        $this->assertSame('700.00', number_format((float) $blue->fresh()->cost_price, 2, '.', ''));

        // Red sendiri sudah selesai -> salinan TIDAK ikut menyalin status selesai.
        $this->postJson("/api/v1/variants/{$red->id}/bom/complete")->assertOk();

        $this->copyFrom($blue, $red, ['confirm_replace' => true])->assertOk()->assertJsonPath('results.0.reopened', true);

        $fresh = $blue->fresh();
        $this->assertFalse($fresh->bom_complete);
        $this->assertSame('700.00', number_format((float) $fresh->cost_price, 2, '.', ''), 'cost price keeps its last value');
        $green = $red->product->variants()->where('variant_name', 'Green')->first();
        $this->copyFrom($green, $red)->assertOk()->assertJsonPath('summary.bom_complete', false);
    }

    public function test_legacy_rows_are_copied_as_legacy(): void
    {
        [$red, $blue] = $this->product();
        ProductVariantBomLine::factory()->create(['product_variant_id' => $red->id, 'material_id' => Material::factory()->create(['name' => 'Tali Lama'])->id, 'qty_needed' => 2]);

        $this->copyFrom($blue, $red)->assertOk();

        $legacy = collect($this->rows($blue))->firstWhere('item_name', 'Tali Lama');
        $this->assertTrue($legacy['is_legacy']);
        $this->assertNull($legacy['purchase_order_item_id']);
    }

    public function test_creating_a_variant_can_copy_a_bom_from_a_sibling(): void
    {
        [$red] = $this->product();

        $created = $this->postJson("/api/v1/products/{$red->product_id}/variants", [
            'variant_name' => 'Yellow', 'sell_price' => 20000, 'cost_price' => 0, 'copy_bom_from_variant_id' => $red->id,
        ])->assertCreated();

        $this->assertCount(3, $this->rows(ProductVariant::findOrFail($created->json('id'))));

        $foreign = $this->variantFor($this->seller);
        $this->postJson("/api/v1/products/{$red->product_id}/variants", [
            'variant_name' => 'Pink', 'sell_price' => 1, 'copy_bom_from_variant_id' => $foreign->id,
        ])->assertStatus(422);
        $this->assertNull($red->product->variants()->where('variant_name', 'Pink')->first(), 'a refused copy leaves no half-created variant');
    }

    public function test_each_copy_is_audited_and_both_menus_are_required(): void
    {
        [$red, $blue] = $this->product();
        $this->copyFrom($blue, $red)->assertOk();

        $log = ActivityLog::where('action', 'bom_copied')->firstOrFail();
        $this->assertSame($blue->id, $log->entity_id);
        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertSame($red->id, $log->new_values['source_variant_id']);
        $this->assertSame(3, $log->new_values['rows']);

        $productsOnly = User::factory()->create(['role_id' => Role::factory()->create(['name' => 'Staf Produk', 'menu_keys' => ['products']])->id]);
        $this->actingAs($productsOnly, 'sanctum');
        $this->copyFrom($blue, $red, ['confirm_replace' => true])->assertForbidden();
        $this->copyOut($red, 'all')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');
        $this->copyOut($red, 'next')->assertForbidden();
    }

    public function test_copy_never_touches_the_other_data_mode(): void
    {
        [$red, $blue] = $this->product();
        $demoVariantId = ModeGate::runAs('demo', fn () => $this->variantFor(Artist::factory()->create())->id);

        $this->copyFrom(ProductVariant::withoutGlobalScopes()->findOrFail($demoVariantId), $red)->assertNotFound();
        $this->assertSame(0, ProductVariantBomLine::withoutGlobalScopes()->where('product_variant_id', $demoVariantId)->count());
    }
}
