<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F13.4 (PRD 7.13, prioritas M) — log aktivitas untuk tindakan sensitif:
 * hapus data, penyesuaian stok, ubah harga.
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_deleting_an_artist_writes_an_activity_log(): void
    {
        $owner = $this->actingAsRole('owner');
        $artist = Artist::factory()->create(['code' => 'RYU']);

        $this->deleteJson("/api/v1/artists/{$artist->id}")->assertStatus(204);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $owner->id,
            'action' => 'deleted',
            'entity_type' => 'Artist',
            'entity_id' => $artist->id,
        ]);
    }

    public function test_blocked_artist_delete_does_not_write_an_activity_log(): void
    {
        // Guard menolak SEBELUM transaksi delete+log berjalan — tidak boleh
        // ada jejak "deleted" untuk sesuatu yang sebenarnya tidak terhapus.
        $this->actingAsRole('owner');
        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'is_active' => true]);

        $this->deleteJson("/api/v1/artists/{$artist->id}")->assertStatus(409);

        $this->assertDatabaseMissing('activity_logs', [
            'entity_type' => 'Artist', 'entity_id' => $artist->id,
        ]);
    }

    public function test_deleting_a_category_writes_an_activity_log(): void
    {
        $owner = $this->actingAsRole('owner');
        $category = Category::factory()->create(['code' => 'KY']);

        $this->deleteJson("/api/v1/categories/{$category->id}")->assertStatus(204);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $owner->id,
            'action' => 'deleted',
            'entity_type' => 'Category',
            'entity_id' => $category->id,
        ]);
    }

    public function test_deleting_a_product_writes_an_activity_log(): void
    {
        $owner = $this->actingAsRole('owner');
        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);

        $this->deleteJson("/api/v1/products/{$product->id}")->assertStatus(204);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $owner->id,
            'action' => 'deleted',
            'entity_type' => 'Product',
            'entity_id' => $product->id,
        ]);
    }

    public function test_changing_variant_price_writes_a_price_changed_log_with_snapshots(): void
    {
        $owner = $this->actingAsRole('owner');
        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create([
            'sku' => 'AAAKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 10,
        ]);

        $this->putJson("/api/v1/variants/{$variant->id}", ['sell_price' => 15000])
            ->assertOk();

        $log = \App\Models\ActivityLog::where('entity_type', 'ProductVariant')->where('entity_id', $variant->id)->first();

        $this->assertNotNull($log);
        $this->assertSame($owner->id, $log->user_id);
        $this->assertSame('price_changed', $log->action);
        $this->assertEquals('10000.00', $log->old_values['sell_price']);
        $this->assertEquals('15000.00', $log->new_values['sell_price']);
    }

    public function test_manual_stock_adjustment_writes_a_stock_adjusted_log(): void
    {
        $owner = $this->actingAsRole('owner');
        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create([
            'sku' => 'AAAKYAAA0002', 'sell_price' => 10000, 'current_stock' => 10,
        ]);

        $this->postJson('/api/v1/stock/adjustments', [
            'reason' => 'Koreksi stok opname',
            'items' => [['variant_id' => $variant->id, 'qty_change' => -3]],
        ])->assertCreated();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $owner->id,
            'action' => 'stock_adjusted',
            'entity_type' => 'ProductVariant',
            'entity_id' => $variant->id,
        ]);
    }

    public function test_owner_can_list_activity_logs_filtered_by_entity(): void
    {
        $owner = $this->actingAsRole('owner');
        $artist = Artist::factory()->create();
        $this->deleteJson("/api/v1/artists/{$artist->id}")->assertStatus(204);

        $response = $this->getJson("/api/v1/activity-logs?entity_type=Artist&entity_id={$artist->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('deleted', $response->json('data.0.action'));
        $this->assertSame($owner->name, $response->json('data.0.user_name'));
    }

    public function test_cashier_cannot_list_activity_logs(): void
    {
        $this->actingAsRole('cashier');

        $this->getJson('/api/v1/activity-logs')->assertStatus(403);
    }

    public function test_routine_sale_stock_movement_does_not_write_an_activity_log(): void
    {
        // F13.4 secara spesifik menyasar PENYESUAIAN manual, bukan setiap
        // pergerakan stok — penjualan normal lewat StockService::
        // applyMovement(type: 'sale') tidak boleh membanjiri activity_logs.
        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create([
            'sku' => 'AAAKYAAA0003', 'sell_price' => 10000, 'current_stock' => 10,
        ]);

        app(\App\Services\StockService::class)->applyMovement($variant, 'sale', -2);

        $this->assertDatabaseMissing('activity_logs', [
            'entity_type' => 'ProductVariant', 'entity_id' => $variant->id,
        ]);
    }
}
