<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 028 US2 — menambah/mengubah/menghapus baris tab (FR-006, FR-007, FR-017).
 */
class TabLinesTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;
    private int $tab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->tab = $this->apiOpenTab($this->makeShift($this->cashier)->id)->json('id');
    }

    public function test_adding_a_line_computes_price_and_totals_on_the_server(): void
    {
        $v = $this->makeVariant(stock: 10, price: 28000);

        $this->apiAddLine($this->tab, $v->id, 2)
            ->assertCreated()
            ->assertJsonPath('subtotal', '56000.00')
            ->assertJsonPath('total_amount', '56000.00')
            ->assertJsonPath('balance', '56000.00')
            ->assertJsonPath('lines.0.qty', 2)
            ->assertJsonPath('lines.0.unit_price', '28000.00')
            ->assertJsonPath('lines.0.line_total', '56000.00')
            ->assertJsonPath('lines.0.sent', false)
            ->assertJsonPath('lines.0.prep_status', null);
    }

    public function test_a_price_sent_by_the_client_is_ignored(): void
    {
        $v = $this->makeVariant(price: 25000);

        $this->apiAddLine($this->tab, $v->id, 1, ['sell_price' => 1, 'unit_price' => 1, 'line_total' => 1, 'total_amount' => 1])
            ->assertCreated()->assertJsonPath('total_amount', '25000.00');

        $item = OrderItem::first();
        $this->assertEquals(25000, $item->sell_price);
        $this->assertEquals(12500, $item->cost_price);
        $this->assertSame($v->product->artist_id, $item->artist_id);
        $this->assertSame($v->sku, $item->sku_snapshot);
    }

    public function test_the_tab_payload_never_exposes_cost(): void
    {
        $this->apiAddLine($this->tab, $this->makeVariant()->id);

        $json = json_encode($this->getJson("/api/v1/tabs/{$this->tab}")->assertOk()->json());
        $this->assertStringNotContainsString('cost', $json);
    }

    public function test_adding_the_same_variant_again_merges_into_the_unsent_line(): void
    {
        $v = $this->makeVariant(stock: 10, price: 10000);

        $this->apiAddLine($this->tab, $v->id, 2)->assertCreated();
        $res = $this->apiAddLine($this->tab, $v->id, 1)->assertCreated();

        $res->assertJsonCount(1, 'lines')->assertJsonPath('lines.0.qty', 3)->assertJsonPath('total_amount', '30000.00');
    }

    public function test_a_different_note_keeps_lines_separate(): void
    {
        $v = $this->makeVariant(stock: 10);

        $this->apiAddLine($this->tab, $v->id, 1)->assertCreated();
        $res = $this->apiAddLine($this->tab, $v->id, 1, ['note' => 'tanpa es'])->assertCreated();

        $res->assertJsonCount(2, 'lines');
        $this->assertContains('tanpa es', collect($res->json('lines'))->pluck('note')->all());
    }

    public function test_two_different_items_added_one_after_another_are_both_kept(): void
    {
        $a = $this->makeVariant();
        $b = $this->makeVariant();

        $this->apiAddLine($this->tab, $a->id)->assertCreated();
        $this->apiAddLine($this->tab, $b->id)->assertCreated();

        $this->assertSame(2, Order::find($this->tab)->items()->count());
    }

    public function test_the_quantity_of_an_unsent_line_can_change(): void
    {
        $v = $this->makeVariant(stock: 10, price: 10000);
        $line = $this->apiAddLine($this->tab, $v->id, 1)->json('lines.0.id');

        $this->patchJson("/api/v1/tabs/{$this->tab}/items/{$line}", ['qty' => 4])
            ->assertOk()->assertJsonPath('lines.0.qty', 4)->assertJsonPath('total_amount', '40000.00');
    }

    public function test_a_note_can_be_edited_on_an_unsent_line(): void
    {
        $line = $this->apiAddLine($this->tab, $this->makeVariant()->id)->json('lines.0.id');

        $this->patchJson("/api/v1/tabs/{$this->tab}/items/{$line}", ['note' => 'pedas'])
            ->assertOk()->assertJsonPath('lines.0.note', 'pedas');
    }

    public function test_an_unsent_line_can_be_removed(): void
    {
        $v = $this->makeVariant(price: 10000);
        $keep = $this->apiAddLine($this->tab, $this->makeVariant(price: 5000)->id)->json('lines.0.id');
        $drop = $this->apiAddLine($this->tab, $v->id)->json('lines.1.id');

        $this->deleteJson("/api/v1/tabs/{$this->tab}/items/{$drop}")
            ->assertOk()->assertJsonCount(1, 'lines')->assertJsonPath('lines.0.id', $keep)->assertJsonPath('total_amount', '5000.00');
    }

    public function test_a_sent_line_cannot_be_edited_through_the_normal_endpoint(): void
    {
        $line = $this->apiAddLine($this->tab, $this->makeVariant(stock: 5)->id)->json('lines.0.id');
        OrderItem::whereKey($line)->update(['sent_at' => now()]);

        $this->patchJson("/api/v1/tabs/{$this->tab}/items/{$line}", ['qty' => 3])->assertStatus(409);
        $this->assertSame(1, OrderItem::find($line)->qty);
    }

    public function test_an_inactive_variant_cannot_be_added(): void
    {
        $v = $this->makeVariant(variantOverrides: ['is_active' => false]);

        $this->apiAddLine($this->tab, $v->id)->assertStatus(409);
        $this->assertSame(0, OrderItem::count());
    }

    public function test_insufficient_stock_is_refused_with_the_available_quantity_and_leaves_the_tab_untouched(): void
    {
        $ok = $this->makeVariant(stock: 10, price: 5000);
        $scarce = $this->makeVariant(stock: 2);
        $this->apiAddLine($this->tab, $ok->id)->assertCreated();

        $res = $this->apiAddLine($this->tab, $scarce->id, 5)->assertStatus(409);

        $this->assertStringContainsString('2', json_encode($res->json('errors')));
        $this->assertSame(1, Order::find($this->tab)->items()->count());
        $this->assertSame(2, $scarce->fresh()->current_stock);
    }

    public function test_a_made_to_order_dish_never_blocks_on_stock(): void
    {
        $dish = $this->makeVariant(stock: 0, price: 30000, variantOverrides: ['track_stock' => false]);

        $this->apiAddLine($this->tab, $dish->id, 3)->assertCreated()->assertJsonPath('total_amount', '90000.00');
        $this->assertSame(0, $dish->fresh()->current_stock);
    }

    public function test_validation_rejects_a_zero_quantity_and_a_missing_variant(): void
    {
        $this->apiAddLine($this->tab, $this->makeVariant()->id, 0)->assertStatus(422)->assertJsonValidationErrors(['qty']);
        $this->postJson("/api/v1/tabs/{$this->tab}/items", ['qty' => 1])->assertStatus(422)->assertJsonValidationErrors(['variant_id']);
    }

    public function test_lines_cannot_be_added_to_a_tab_that_is_no_longer_open(): void
    {
        Order::whereKey($this->tab)->update(['status' => 'completed']);

        $this->apiAddLine($this->tab, $this->makeVariant()->id)->assertStatus(409);
    }

    public function test_a_line_of_another_tab_is_not_found(): void
    {
        $other = $this->apiOpenTab($this->makeShift($this->cashier)->id)->json('id');
        $otherLine = $this->apiAddLine($other, $this->makeVariant()->id)->json('lines.0.id');

        $this->patchJson("/api/v1/tabs/{$this->tab}/items/{$otherLine}", ['qty' => 2])->assertNotFound();
        $this->deleteJson("/api/v1/tabs/{$this->tab}/items/{$otherLine}")->assertNotFound();
    }
}
