<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 084 US1 — POS web (POST /orders) menerima pilihan modifier. Aturan modifier tetap hanya di
 * ModifierResolver; di sini diuji bahwa jalur kasir ritel memakainya, harga dihitung SERVER
 * (dasar + selisih), dan snapshot tersimpan di baris order.
 */
class OrderModifierTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private CashierSession $session;
    private ProductVariant $variant;
    private ModifierGroup $required;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');

        $outlet = Outlet::factory()->create();
        $this->session = CashierSession::factory()->create([
            'user_id' => $this->cashier->id, 'status' => 'open', 'outlet_id' => $outlet->id,
        ]);

        $product = Product::factory()->create([
            'home_outlet_id' => $outlet->id, 'category_id' => Category::factory()->create()->id,
        ]);
        $this->variant = $product->variants()->create([
            'sku' => 'MODSKU000001', 'sell_price' => 20000, 'cost_price' => 8000, 'current_stock' => 10,
        ]);

        $this->required = ModifierGroup::factory()->create([
            'name' => 'Level gula', 'selection_type' => 'single', 'is_required' => true,
        ]);
        $this->required->products()->attach($product->id);
        $this->optLess = $this->required->options()->create(['name' => 'Kurang manis', 'price_delta' => 0]);
        $this->optExtra = $this->required->options()->create(['name' => 'Ekstra shot', 'price_delta' => 3000]);
    }

    private ?object $optLess = null;
    private ?object $optExtra = null;

    private function payload(array $item, int $paid = 100000): array
    {
        return [
            'session_id' => $this->session->id,
            'local_ref' => (string) Str::uuid(),
            'items' => [array_merge(['variant_id' => $this->variant->id, 'qty' => 2], $item)],
            'payments' => [['method' => 'cash', 'amount' => $paid]],
        ];
    }

    public function test_modifier_delta_is_added_server_side_and_snapshot_is_stored(): void
    {
        $r = $this->postJson('/api/v1/orders', $this->payload(['modifier_option_ids' => [$this->optExtra->id]]));

        $r->assertCreated();
        // (20.000 + 3.000) × 2 = 46.000; pajak bawaan tarif 0 pada fixture.
        $this->assertEquals(46000, (float) $r->json('subtotal'));
        $item = $r->json('items.0');
        $this->assertEquals(23000, (float) $item['sell_price']);
        $this->assertSame('Ekstra shot', $item['modifiers'][0]['option']);
        $this->assertSame('Level gula', $item['modifiers'][0]['group']);
    }

    public function test_client_cannot_set_the_price(): void
    {
        $r = $this->postJson('/api/v1/orders', $this->payload([
            'modifier_option_ids' => [$this->optLess->id], 'sell_price' => 1, 'unit_price' => 1,
        ]));

        $r->assertCreated();
        $this->assertEquals(20000, (float) $r->json('items.0.sell_price'));
    }

    public function test_required_group_left_empty_is_rejected_with_422(): void
    {
        $this->postJson('/api/v1/orders', $this->payload([]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('modifier_option_ids');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, $this->variant->fresh()->current_stock);
    }

    public function test_option_from_another_group_is_rejected_with_422(): void
    {
        $other = ModifierGroup::factory()->create(['name' => 'Topping']);
        $foreign = $other->options()->create(['name' => 'Boba', 'price_delta' => 5000]);

        $this->postJson('/api/v1/orders', $this->payload(['modifier_option_ids' => [$this->optLess->id, $foreign->id]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('modifier_option_ids');
    }

    public function test_variant_without_groups_behaves_as_before(): void
    {
        $plain = Product::factory()->create([
            'home_outlet_id' => $this->session->outlet_id, 'category_id' => Category::factory()->create()->id,
        ])->variants()->create(['sku' => 'PLAIN0000001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 5]);

        $r = $this->postJson('/api/v1/orders', [
            'session_id' => $this->session->id,
            'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $plain->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ]);

        $r->assertCreated();
        $this->assertEquals(10000, (float) $r->json('total_amount'));
        $this->assertEmpty($r->json('items.0.modifiers'));
    }

    public function test_modifier_priced_line_with_item_discount_keeps_totals_consistent(): void
    {
        $r = $this->postJson('/api/v1/orders', $this->payload([
            'modifier_option_ids' => [$this->optExtra->id], 'discount_amount' => 1000,
        ]));

        $r->assertCreated();
        // (23.000 × 2) − 1.000 = 45.000; line_total sama dengan subtotal order.
        $this->assertEquals(45000, (float) $r->json('items.0.line_total'));
        $this->assertEquals(45000, (float) $r->json('subtotal'));
    }
}
