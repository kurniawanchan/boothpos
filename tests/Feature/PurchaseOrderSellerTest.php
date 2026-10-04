<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Artist;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariantBomLine;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 034-seller-po-bom (US2) — purchase order milik satu seller. Seller
 * menjadi dasar filter selector BOM, jadi aturannya (wajib saat buat,
 * PO lama boleh kosong lalu ditetapkan eksplisit, terkunci selama dipakai
 * BOM) diuji di sini.
 */
class PurchaseOrderSellerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
    }

    private function payload(?int $artistId, array $extra = []): array
    {
        return array_merge([
            'vendor_id' => Vendor::factory()->create()->id,
            'artist_id' => $artistId,
            'items' => [['line_type' => 'material', 'material_id' => Material::factory()->create()->id, 'qty' => 10, 'unit_price' => 500]],
        ], $extra);
    }

    public function test_create_requires_an_existing_seller(): void
    {
        $this->postJson('/api/v1/purchase-orders', $this->payload(null))
            ->assertStatus(422)->assertJsonValidationErrors('artist_id');

        $this->postJson('/api/v1/purchase-orders', $this->payload(999999))
            ->assertStatus(422)->assertJsonValidationErrors('artist_id');
    }

    public function test_create_stores_the_seller_and_every_payload_exposes_it(): void
    {
        $artist = Artist::factory()->create(['name' => 'Seller A']);

        $id = $this->postJson('/api/v1/purchase-orders', $this->payload($artist->id))
            ->assertCreated()
            ->assertJsonPath('artist_id', $artist->id)
            ->assertJsonPath('artist_name', 'Seller A')
            ->json('id');

        $this->getJson("/api/v1/purchase-orders/{$id}")->assertOk()->assertJsonPath('artist_name', 'Seller A');
        $this->getJson('/api/v1/purchase-orders')->assertOk()->assertJsonPath('data.0.artist_name', 'Seller A');
    }

    public function test_a_soft_deleted_sellers_name_still_resolves_on_old_purchase_orders(): void
    {
        $artist = Artist::factory()->create(['name' => 'Seller Lama']);
        $po = PurchaseOrder::factory()->create(['artist_id' => $artist->id]);
        $artist->delete();

        $this->getJson("/api/v1/purchase-orders/{$po->id}")->assertOk()->assertJsonPath('artist_name', 'Seller Lama');
    }

    public function test_list_filters_by_seller_and_shows_legacy_purchase_orders_without_one(): void
    {
        $a = Artist::factory()->create();
        $b = Artist::factory()->create();
        PurchaseOrder::factory()->create(['artist_id' => $a->id]);
        PurchaseOrder::factory()->create(['artist_id' => $b->id]);
        $legacy = PurchaseOrder::factory()->legacy()->create();

        $this->getJson("/api/v1/purchase-orders?artist_id={$a->id}")->assertOk()->assertJsonPath('meta.total', 1);

        $rows = collect($this->getJson('/api/v1/purchase-orders')->assertOk()->json('data'))->keyBy('id');
        $this->assertNull($rows[$legacy->id]['artist_id']);
        $this->assertNull($rows[$legacy->id]['artist_name']);
    }

    public function test_assigning_a_seller_to_a_legacy_purchase_order_is_allowed_and_audited(): void
    {
        $artist = Artist::factory()->create();
        $po = PurchaseOrder::factory()->legacy()->create(['status' => 'ordered']);

        $this->putJson("/api/v1/purchase-orders/{$po->id}", ['artist_id' => $artist->id])
            ->assertOk()->assertJsonPath('artist_id', $artist->id);

        $log = ActivityLog::where('action', 'purchase_order_seller_assigned')->where('entity_id', $po->id)->first();
        $this->assertNotNull($log);
        $this->assertSame($this->owner->id, $log->user_id);
    }

    public function test_changing_the_seller_is_allowed_when_no_bom_uses_the_purchase_order_and_audited(): void
    {
        $a = Artist::factory()->create();
        $b = Artist::factory()->create();
        $po = PurchaseOrder::factory()->create(['artist_id' => $a->id, 'status' => 'ordered']);

        $this->putJson("/api/v1/purchase-orders/{$po->id}", ['artist_id' => $b->id])
            ->assertOk()->assertJsonPath('artist_id', $b->id);

        $log = ActivityLog::where('action', 'purchase_order_seller_changed')->where('entity_id', $po->id)->first();
        $this->assertNotNull($log);
        $this->assertSame($a->id, $log->old_values['artist_id']);
        $this->assertSame($b->id, $log->new_values['artist_id']);
    }

    public function test_changing_the_seller_is_refused_once_a_bom_row_uses_one_of_its_lines(): void
    {
        $a = Artist::factory()->create();
        $b = Artist::factory()->create();
        $po = PurchaseOrder::factory()->create(['artist_id' => $a->id, 'status' => 'ordered']);
        $item = $po->items()->create(['line_type' => 'material', 'material_id' => Material::factory()->create()->id, 'qty' => 5, 'unit_price' => 100, 'line_total' => 500]);
        $variant = Product::factory()->create(['artist_id' => $a->id])->variants()->create(['sku' => 'SEL000001', 'sell_price' => 1, 'cost_price' => 0, 'current_stock' => 0]);
        ProductVariantBomLine::factory()->fromPoLine($item)->create(['product_variant_id' => $variant->id]);

        $this->putJson("/api/v1/purchase-orders/{$po->id}", ['artist_id' => $b->id])->assertStatus(409);

        $this->assertSame($a->id, $po->fresh()->artist_id);
        $this->assertSame(0, ActivityLog::where('action', 'purchase_order_seller_changed')->count());

        // Mengirim seller yang SAMA tidak dianggap perubahan.
        $this->putJson("/api/v1/purchase-orders/{$po->id}", ['artist_id' => $a->id, 'notes' => 'x'])->assertOk();
    }

    public function test_detail_items_report_how_many_bom_rows_use_them(): void
    {
        $artist = Artist::factory()->create();
        $po = PurchaseOrder::factory()->create(['artist_id' => $artist->id, 'status' => 'ordered']);
        $used = $po->items()->create(['line_type' => 'material', 'material_id' => Material::factory()->create()->id, 'qty' => 5, 'unit_price' => 100, 'line_total' => 500]);
        $free = $po->items()->create(['line_type' => 'service', 'description' => 'Rakit', 'qty' => 1, 'unit_price' => 100, 'line_total' => 100]);
        $product = Product::factory()->create(['artist_id' => $artist->id]);
        foreach (['SEL000002', 'SEL000003'] as $sku) {
            $variant = $product->variants()->create(['sku' => $sku, 'sell_price' => 1, 'cost_price' => 0, 'current_stock' => 0]);
            ProductVariantBomLine::factory()->fromPoLine($used)->create(['product_variant_id' => $variant->id]);
        }

        $items = collect($this->getJson("/api/v1/purchase-orders/{$po->id}")->assertOk()->json('items'))->keyBy('id');

        $this->assertSame(2, $items[$used->id]['used_in_bom_count']);
        $this->assertSame(0, $items[$free->id]['used_in_bom_count']);
    }

    public function test_an_optional_linked_product_is_still_accepted_and_stored(): void
    {
        $artist = Artist::factory()->create();
        $product = Product::factory()->create();

        $this->postJson('/api/v1/purchase-orders', $this->payload($artist->id, [
            'items' => [['line_type' => 'material', 'material_id' => Material::factory()->create()->id, 'product_id' => $product->id, 'qty' => 1, 'unit_price' => 100]],
        ]))->assertCreated()->assertJsonPath('items.0.product_id', $product->id);
    }
}
