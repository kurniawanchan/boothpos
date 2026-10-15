<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Artist;
use App\Models\Material;
use App\Models\Payment;
use App\Models\ProductVariantBomLine;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBomFixtures;
use Tests\TestCase;

/**
 * 035-po-row-actions — Edit di setiap status (draft: semuanya; setelahnya:
 * vendor/seller/catatan, baris terkunci), audit `purchase_order_updated`, dan
 * Delete hanya untuk draft (dengan penjagaan pembayaran dan BOM).
 */
class PurchaseOrderRowActionsTest extends TestCase
{
    use BuildsBomFixtures, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
    }

    private function makePo(string $status, array $attrs = []): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create(['status' => $status, 'created_by' => $this->owner->id] + $attrs);
        $po->items()->create([
            'line_type' => 'material', 'material_id' => Material::factory()->create()->id,
            'qty' => 10, 'unit_price' => 500, 'line_total' => 5000,
        ]);
        $po->update(['subtotal' => 5000, 'total_amount' => 5000]);

        return $po->fresh();
    }

    private function linePayload(float $price = 700, float $qty = 2): array
    {
        return [['line_type' => 'material', 'material_id' => Material::factory()->create()->id, 'qty' => $qty, 'unit_price' => $price]];
    }

    // ===================================================================
    // US2 — Edit
    // ===================================================================

    public function test_vendor_seller_and_notes_can_be_edited_in_every_status(): void
    {
        foreach (['draft', 'ordered', 'received', 'paid', 'cancelled'] as $status) {
            $po = $this->makePo($status);
            $vendor = Vendor::factory()->create();
            $seller = Artist::factory()->create();

            $this->putJson("/api/v1/purchase-orders/{$po->id}", ['vendor_id' => $vendor->id, 'artist_id' => $seller->id, 'notes' => "catatan {$status}"])
                ->assertOk()->assertJsonPath('vendor_id', $vendor->id)->assertJsonPath('artist_id', $seller->id)->assertJsonPath('notes', "catatan {$status}");

            $fresh = $po->fresh();
            $this->assertSame($vendor->id, $fresh->vendor_id, $status);
            $this->assertSame($seller->id, $fresh->artist_id, $status);
        }
    }

    public function test_lines_are_locked_after_draft_and_editable_in_a_draft_with_totals_recalculated(): void
    {
        foreach (['ordered', 'received', 'paid', 'cancelled'] as $status) {
            $po = $this->makePo($status);

            $this->putJson("/api/v1/purchase-orders/{$po->id}", ['items' => $this->linePayload()])->assertStatus(409);

            $this->assertSame(1, $po->items()->count(), $status);
            $this->assertSame('5000.00', number_format((float) $po->fresh()->total_amount, 2, '.', ''), $status);
        }

        $draft = $this->makePo('draft');
        $this->putJson("/api/v1/purchase-orders/{$draft->id}", ['items' => $this->linePayload(700, 2)])
            ->assertOk()->assertJsonPath('total_amount', '1400.00');
        $this->assertSame(1, $draft->items()->count());
    }

    public function test_the_seller_cannot_change_once_a_bom_uses_one_of_the_lines(): void
    {
        $sellerA = Artist::factory()->create();
        $variant = $this->variantFor($sellerA);
        [$item] = $this->po($sellerA, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 500]]);
        $this->addItems($variant, [['purchase_order_item_id' => $item->id]])->assertCreated();
        $po = $item->purchaseOrder;
        $other = Artist::factory()->create();

        $this->putJson("/api/v1/purchase-orders/{$po->id}", ['artist_id' => $other->id])->assertStatus(409);

        $this->assertSame($sellerA->id, $po->fresh()->artist_id);
        // vendor/notes tetap boleh diubah walau seller terkunci
        $this->putJson("/api/v1/purchase-orders/{$po->id}", ['notes' => 'masih bisa'])->assertOk();
    }

    public function test_a_vendor_change_writes_one_purchase_order_updated_log_with_old_and_new_values(): void
    {
        $po = $this->makePo('ordered');
        $oldVendor = $po->vendor_id;
        $vendor = Vendor::factory()->create();

        $this->putJson("/api/v1/purchase-orders/{$po->id}", ['vendor_id' => $vendor->id])->assertOk();

        $log = ActivityLog::where('action', 'purchase_order_updated')->where('entity_id', $po->id)->sole();
        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertSame('PurchaseOrder', $log->entity_type);
        $this->assertSame($oldVendor, $log->old_values['vendor_id']);
        $this->assertSame($vendor->id, $log->new_values['vendor_id']);
    }

    public function test_rewriting_the_lines_of_a_draft_is_logged_with_the_totals_and_line_count(): void
    {
        $po = $this->makePo('draft');

        $this->putJson("/api/v1/purchase-orders/{$po->id}", ['items' => array_merge($this->linePayload(700, 2), $this->linePayload(100, 1))])->assertOk();

        $log = ActivityLog::where('action', 'purchase_order_updated')->where('entity_id', $po->id)->sole();
        $this->assertSame(1, $log->old_values['lines']);
        $this->assertSame(2, $log->new_values['lines']);
        $this->assertEquals(5000, $log->old_values['total_amount']);
        $this->assertEquals(1500, $log->new_values['total_amount']);
    }

    public function test_a_notes_only_edit_writes_no_updated_log(): void
    {
        $po = $this->makePo('paid');

        $this->putJson("/api/v1/purchase-orders/{$po->id}", ['notes' => 'hanya catatan'])->assertOk();

        $this->assertSame(0, ActivityLog::where('action', 'purchase_order_updated')->count());
    }

    public function test_assigning_a_seller_logs_only_the_seller_entry_not_a_second_updated_entry(): void
    {
        $po = $this->makePo('ordered', ['artist_id' => null]);
        $po->update(['artist_id' => null]);
        $seller = Artist::factory()->create();

        $this->putJson("/api/v1/purchase-orders/{$po->id}", ['artist_id' => $seller->id])->assertOk();

        $this->assertSame(1, ActivityLog::where('action', 'purchase_order_seller_assigned')->where('entity_id', $po->id)->count());
        $this->assertSame(0, ActivityLog::where('action', 'purchase_order_updated')->count());
    }

    public function test_editing_needs_the_purchase_orders_menu_and_respects_the_data_mode(): void
    {
        $po = $this->makePo('ordered');

        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');
        $this->putJson("/api/v1/purchase-orders/{$po->id}", ['notes' => 'x'])->assertForbidden();

        $this->actingAs($this->owner, 'sanctum');
        $demo = ModeGate::runAs('demo', fn () => $this->makePo('ordered'));
        $this->putJson("/api/v1/purchase-orders/{$demo->id}", ['notes' => 'x'])->assertNotFound();
    }

    // ===================================================================
    // US3 — Delete
    // ===================================================================

    public function test_a_draft_can_be_deleted_and_the_deletion_is_audited(): void
    {
        $po = $this->makePo('draft');

        $this->deleteJson("/api/v1/purchase-orders/{$po->id}")->assertNoContent();

        $this->assertSoftDeleted('purchase_orders', ['id' => $po->id]);
        $log = ActivityLog::where('action', 'deleted')->where('entity_type', 'PurchaseOrder')->where('entity_id', $po->id)->sole();
        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertSame($po->po_number, $log->old_values['po_number']);
    }

    public function test_every_non_draft_status_is_refused_with_the_cancel_instead_message_and_nothing_is_deleted(): void
    {
        foreach (['ordered', 'received', 'paid', 'cancelled'] as $status) {
            $po = $this->makePo($status);

            $res = $this->deleteJson("/api/v1/purchase-orders/{$po->id}")->assertStatus(409);

            $this->assertMatchesRegularExpression('/cancel|batalkan/i', $res->json('message'), $status);
            $this->assertNotSoftDeleted('purchase_orders', ['id' => $po->id]);
            $this->assertSame(1, $po->items()->count());
        }
        $this->assertSame(0, ActivityLog::where('action', 'deleted')->where('entity_type', 'PurchaseOrder')->count());
    }

    public function test_a_payment_blocks_deletion_even_on_a_draft(): void
    {
        $po = $this->makePo('draft');
        Payment::create(['purchase_order_id' => $po->id, 'method' => 'cash', 'purpose' => 'full', 'amount' => 100, 'verification' => 'verified', 'paid_at' => now()]);

        $res = $this->deleteJson("/api/v1/purchase-orders/{$po->id}")->assertStatus(409);

        $this->assertMatchesRegularExpression('/payment|pembayaran/i', $res->json('message'));
        $this->assertNotSoftDeleted('purchase_orders', ['id' => $po->id]);
    }

    public function test_a_bom_row_that_uses_a_line_blocks_deletion_even_on_a_draft(): void
    {
        $po = $this->makePo('draft');
        $variant = $this->variantFor($po->artist);
        ProductVariantBomLine::factory()->fromPoLine($po->items()->first())->create(['product_variant_id' => $variant->id]);

        $res = $this->deleteJson("/api/v1/purchase-orders/{$po->id}")->assertStatus(409);

        $this->assertMatchesRegularExpression('/bom/i', $res->json('message'));
        $this->assertNotSoftDeleted('purchase_orders', ['id' => $po->id]);
        $this->assertSame(1, $po->items()->count());
    }

    public function test_deleting_needs_the_purchase_orders_menu(): void
    {
        $po = $this->makePo('draft');

        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');
        $this->deleteJson("/api/v1/purchase-orders/{$po->id}")->assertForbidden();

        $this->assertNotSoftDeleted('purchase_orders', ['id' => $po->id]);
    }
}
