<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Event;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Spec 045 FR-015/FR-016: hapus varian; nomor yang dihapus boleh dipakai lagi. */
class VariantRemovalTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $artist = Artist::factory()->create(['code' => 'SPF']);
        $category = Category::factory()->create(['code' => 'KC']);
        $this->product = Product::factory()->create([
            'artist_id' => $artist->id, 'category_id' => $category->id,
            'code_prefix' => 'SPF-KC-S', 'product_segment' => 'S',
        ]);
        foreach (['SPF-KC-S-1', 'SPF-KC-S-2', 'SPF-KC-S-25'] as $sku) {
            $this->product->variants()->create(['sku' => $sku, 'variant_name' => $sku, 'sell_price' => 15000, 'current_stock' => 0]);
        }
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    private function variant(string $sku): ProductVariant
    {
        return ProductVariant::where('sku', $sku)->firstOrFail();
    }

    /** Varian tanpa riwayat dihapus; SKU-nya bebas dipakai lagi. */
    public function test_a_variant_without_history_is_removed_and_its_number_is_free_again(): void
    {
        $variant = $this->variant('SPF-KC-S-25');

        $this->deleteJson("/api/v1/variants/{$variant->id}")
            ->assertOk()
            ->assertJsonPath('removed', true);

        $this->assertSoftDeleted('product_variants', ['id' => $variant->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'variant_deleted', 'entity_id' => $variant->id]);

        $this->putJson("/api/v1/variants/{$this->variant('SPF-KC-S-2')->id}", ['sequence' => '25'])
            ->assertOk();
        $this->assertDatabaseHas('product_variants', ['sku' => 'SPF-KC-S-25']);
    }

    /** Nomor tertinggi yang dihapus tidak menghalangi nomor otomatis berikutnya. */
    public function test_the_generated_number_reuses_the_removed_top_number(): void
    {
        $this->deleteJson("/api/v1/variants/{$this->variant('SPF-KC-S-25')->id}")->assertOk();

        $this->postJson("/api/v1/products/{$this->product->id}/variants", [
            'variant_name' => 'Baru', 'sell_price' => 15000,
        ])->assertCreated()->assertJsonPath('sku', 'SPF-KC-S-3');
    }

    /** Varian yang sudah punya penjualan tidak dihapus, hanya dinonaktifkan. */
    public function test_a_variant_with_a_sale_is_deactivated_not_removed(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open']);
        $variant = $this->variant('SPF-KC-S-25');
        $variant->update(['current_stock' => 10, 'cost_price' => 5000]);

        $this->actingAs($cashier, 'sanctum');
        $this->postJson('/api/v1/orders', [
            'session_id' => $session->id,
            'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 20000]],
        ])->assertCreated();

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->deleteJson("/api/v1/variants/{$variant->id}")
            ->assertOk()
            ->assertJsonPath('removed', false);

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'sku' => 'SPF-KC-S-25', 'is_active' => false, 'deleted_at' => null]);
    }

    /** Cashier tidak boleh menghapus varian (gerbang sama dengan mengubah produk). */
    public function test_a_cashier_cannot_remove_a_variant(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->deleteJson("/api/v1/variants/{$this->variant('SPF-KC-S-25')->id}")->assertForbidden();
        $this->assertDatabaseHas('product_variants', ['sku' => 'SPF-KC-S-25', 'deleted_at' => null]);
    }
}
