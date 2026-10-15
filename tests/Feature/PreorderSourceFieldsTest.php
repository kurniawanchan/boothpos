<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Preorder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 027-preorder-duplicate-split (Foundational) — kolom asal (`source_*`) dan
 * field `source` / `split_children` di payload pre-order.
 */
class PreorderSourceFieldsTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $product = Product::factory()->create([
            'artist_id' => Artist::factory()->create()->id,
            'category_id' => Category::factory()->create()->id,
            'is_preorder' => true,
        ]);
        $this->variant = $product->variants()->create([
            'sku' => 'SRCF0001', 'sell_price' => 10000, 'cost_price' => 5000, 'current_stock' => 0,
        ]);
        $this->customer = Customer::factory()->create();
    }

    private function createPreorder(): array
    {
        return $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertCreated()->json();
    }

    public function test_an_ordinary_preorder_has_no_source_and_no_split_children(): void
    {
        $created = $this->createPreorder();

        $this->getJson("/api/v1/preorders/{$created['id']}")
            ->assertOk()
            ->assertJsonPath('source', null)
            ->assertJsonPath('split_children', []);
    }

    public function test_show_returns_the_source_and_the_split_children(): void
    {
        $parent = $this->createPreorder();
        $child = $this->createPreorder();
        Preorder::whereKey($child['id'])->update([
            'source_preorder_id' => $parent['id'],
            'source_type' => 'split',
            'source_preorder_number' => $parent['preorder_number'],
        ]);

        $this->getJson("/api/v1/preorders/{$child['id']}")
            ->assertOk()
            ->assertJsonPath('source.type', 'split')
            ->assertJsonPath('source.preorder_id', $parent['id'])
            ->assertJsonPath('source.preorder_number', $parent['preorder_number']);

        $this->getJson("/api/v1/preorders/{$parent['id']}")
            ->assertOk()
            ->assertJsonPath('split_children.0.id', $child['id'])
            ->assertJsonPath('split_children.0.preorder_number', $child['preorder_number']);
    }

    public function test_only_split_children_are_listed_not_duplicates(): void
    {
        $parent = $this->createPreorder();
        $copy = $this->createPreorder();
        Preorder::whereKey($copy['id'])->update([
            'source_preorder_id' => $parent['id'],
            'source_type' => 'duplicate',
            'source_preorder_number' => $parent['preorder_number'],
        ]);

        $this->getJson("/api/v1/preorders/{$parent['id']}")
            ->assertOk()
            ->assertJsonPath('split_children', []);
    }

    public function test_deleting_the_source_keeps_the_number_snapshot_but_nulls_the_link(): void
    {
        $parent = $this->createPreorder();
        $child = $this->createPreorder();
        Preorder::whereKey($child['id'])->update([
            'source_preorder_id' => $parent['id'],
            'source_type' => 'duplicate',
            'source_preorder_number' => $parent['preorder_number'],
        ]);

        $this->deleteJson("/api/v1/preorders/{$parent['id']}")->assertNoContent();

        $this->getJson("/api/v1/preorders/{$child['id']}")
            ->assertOk()
            ->assertJsonPath('source.type', 'duplicate')
            ->assertJsonPath('source.preorder_id', null)
            ->assertJsonPath('source.preorder_number', $parent['preorder_number']);
    }
}
