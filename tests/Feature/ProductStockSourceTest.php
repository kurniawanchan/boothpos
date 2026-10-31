<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 087 US6 (T058) — sumber stok per produk. FR-025 = gabungan: bahan modifier dipotong untuk SEMUA produk;
 * `modifier` hanya menghentikan pergerakan stok VARIAN (research D16–D19).
 */
class ProductStockSourceTest extends TestCase
{
    use ModifierMaterialFixtures, OverrideFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
        $this->owner = User::factory()->create(['role' => 'owner']);
    }

    private function useModifierStock(Product $product): void
    {
        $product->forceFill(['stock_source' => Product::STOCK_MODIFIER])->save();
    }

    private function variantMoves(int $variantId)
    {
        return StockMovement::where('variant_id', $variantId)->orderBy('id')->get();
    }

    public function test_products_default_to_variant_stock(): void
    {
        $this->assertSame('variant', $this->indomie->product->fresh()->stock_source);
        $this->assertSame('variant', Product::factory()->create()->fresh()->stock_source);
    }

    public function test_changing_the_source_is_saved_logged_and_warns_about_leftover_variant_stock(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $res = $this->putJson("/api/v1/products/{$this->indomie->product_id}", ['name' => 'Indomie Kuah', 'stock_source' => 'modifier'])->assertOk();

        $res->assertJsonPath('stock_source', 'modifier');
        $this->assertContains('variant_stock_remaining', array_column($res->json('warnings') ?? [], 'code'));
        $this->assertNotContains('no_material_mappings', array_column($res->json('warnings') ?? [], 'code'));
        $log = ActivityLog::where('action', 'stock_source_changed')->sole();
        $this->assertSame('variant', $log->old_values['stock_source']);
        $this->assertSame('modifier', $log->new_values['stock_source']);

        $this->putJson("/api/v1/products/{$this->indomie->product_id}", ['name' => 'Indomie Kuah', 'stock_source' => 'gudang'])
            ->assertStatus(422)->assertJsonValidationErrors('stock_source');
    }

    public function test_a_modifier_product_without_any_material_mapping_gets_a_warning(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $plain = Product::factory()->create(['home_outlet_id' => $this->outletA->id]);

        $res = $this->putJson("/api/v1/products/{$plain->id}", ['name' => $plain->name, 'stock_source' => 'modifier'])->assertOk();

        $this->assertContains('no_material_mappings', array_column($res->json('warnings') ?? [], 'code'));
    }

    public function test_a_modifier_product_never_moves_variant_stock_but_still_deducts_materials(): void
    {
        $this->useModifierStock($this->indomie->product);

        $this->checkout([[$this->indomie, 2, $this->opts('soto', 'telur')]])->assertCreated();

        $this->assertCount(0, $this->variantMoves($this->indomie->id));
        $this->assertSame(48.0, $this->balance('soto'));
        $this->assertSame('modifier', OrderItem::where('variant_id', $this->indomie->id)->value('stock_source'));
    }

    public function test_a_variant_product_moves_variant_stock_and_also_deducts_modifier_materials(): void
    {
        $this->checkout([[$this->nasiGoreng, 1, $this->opts('telur')]])->assertCreated();

        $this->assertSame(['sale'], $this->variantMoves($this->nasiGoreng->id)->pluck('type')->all());
        $this->assertSame(99.0, $this->balance('telur'));
        $this->assertSame('variant', OrderItem::where('variant_id', $this->nasiGoreng->id)->value('stock_source'));
    }

    public function test_tab_lines_of_a_modifier_product_never_move_variant_stock(): void
    {
        $this->useModifierStock($this->indomie->product);
        $tab = $this->apiOpenTab($this->session->id, DiningTable::factory()->create()->id)->json('id');
        $line = max($this->apiAddLine($tab, $this->indomie->id, 1, ['modifier_option_ids' => $this->opts('soto')])->assertCreated()->json('lines.*.id'));
        $this->patchJson("/api/v1/tabs/{$tab}/items/{$line}", ['qty' => 3])->assertOk();
        $this->patchJson("/api/v1/tabs/{$tab}/items/{$line}", ['qty' => 2])->assertOk();
        $this->apiPay($tab, [['method' => 'cash', 'amount' => (float) Order::find($tab)->total_amount]])->assertOk();

        $this->assertCount(0, $this->variantMoves($this->indomie->id));
        $this->assertSame(48.0, $this->balance('soto'));
    }

    public function test_returns_follow_the_line_snapshot_even_if_the_source_changes_mid_tab(): void
    {
        $tab = $this->apiOpenTab($this->session->id, DiningTable::factory()->create()->id)->json('id');
        $tracked = max($this->apiAddLine($tab, $this->indomie->id, 1, ['modifier_option_ids' => $this->opts('soto')])->json('lines.*.id'));

        $this->useModifierStock($this->indomie->product);
        $untracked = max($this->apiAddLine($tab, $this->indomie->id, 1, ['modifier_option_ids' => $this->opts('ayam')])->json('lines.*.id'));

        // Kembali ke `variant` lalu hapus kedua baris: hanya baris yang dulu memotong stok varian yang dikembalikan.
        $this->indomie->product->forceFill(['stock_source' => Product::STOCK_VARIANT])->save();
        $this->deleteJson("/api/v1/tabs/{$tab}/items/{$untracked}")->assertOk();
        $this->deleteJson("/api/v1/tabs/{$tab}/items/{$tracked}")->assertOk();

        $this->assertSame(['sale', 'return'], $this->variantMoves($this->indomie->id)->pluck('type')->all());
    }

    public function test_refunding_a_modifier_product_returns_no_variant_stock(): void
    {
        $this->useModifierStock($this->indomie->product);
        $id = $this->checkout([[$this->indomie, 1, $this->opts('soto')]])->assertCreated()->json('id');

        $subject = ['order_id' => $id, 'reason_code' => 'wrong_item', 'reason' => 'x'];
        $this->postJson("/api/v1/orders/{$id}/refund", $subject + ['override_token' => $this->overrideToken('refund', $subject, $this->outletA->id)])->assertOk();

        $this->assertCount(0, $this->variantMoves($this->indomie->id));
        $this->assertSame(50.0, $this->balance('soto'));
    }

    public function test_pos_payloads_send_effective_tracking_and_management_payloads_send_raw_plus_effective(): void
    {
        $this->useModifierStock($this->indomie->product);

        $pos = collect($this->getJson('/api/v1/products?pos=1&with_variants=1&per_page=100')->assertOk()->json('data'))
            ->firstWhere('id', $this->indomie->product_id);
        $this->assertFalse($pos['variants'][0]['track_stock']);

        $lookup = collect($this->getJson('/api/v1/variants/lookup?pos=1&q='.urlencode('Indomie'))->assertOk()->json('data'))
            ->firstWhere('variant_id', $this->indomie->id);
        $this->assertFalse($lookup['track_stock']);

        $this->actingAs($this->owner, 'sanctum');
        $admin = collect($this->getJson('/api/v1/products?with_variants=1&per_page=100')->assertOk()->json('data'))
            ->firstWhere('id', $this->indomie->product_id);
        $this->assertSame('modifier', $admin['stock_source']);
        $this->assertTrue($admin['variants'][0]['track_stock']);
        $this->assertFalse($admin['variants'][0]['stock_tracked']);

        $teh = collect($this->getJson('/api/v1/products?pos=1&with_variants=1&per_page=100')->json('data'))->firstWhere('id', $this->nasiGoreng->product_id);
        $this->assertTrue($teh['variants'][0]['track_stock']);
    }
}
