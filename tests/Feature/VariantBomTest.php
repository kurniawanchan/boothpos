<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Artist;
use App\Models\Category;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantBomLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBomFixtures;
use Tests\TestCase;

/**
 * 034-seller-po-bom (US1) — BOM varian yang disusun dari baris purchase
 * order milik seller varian itu: selector, tambah/ubah/hapus baris,
 * snapshot biaya, otorisasi, dan audit.
 */
class VariantBomTest extends TestCase
{
    use BuildsBomFixtures, RefreshDatabase;

    private User $owner;

    private Artist $sellerA;

    private Artist $sellerB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
        $this->sellerA = Artist::factory()->create(['name' => 'Seller A']);
        $this->sellerB = Artist::factory()->create(['name' => 'Seller B']);
    }

    // ===================================================================
    // Selector
    // ===================================================================

    public function test_selector_lists_only_the_variants_seller_lines_in_ordered_received_or_paid_orders(): void
    {
        $variant = $this->variantFor($this->sellerA);

        $ok = array_merge(
            $this->po($this->sellerA, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 500]]),
            $this->po($this->sellerA, 'received', [['type' => 'material', 'name' => 'Ring', 'price' => 300]]),
            $this->po($this->sellerA, 'paid', [['type' => 'service', 'name' => 'Assembly', 'price' => 1000]]),
        );
        $this->po($this->sellerA, 'draft', [['type' => 'material', 'name' => 'Draft Item', 'price' => 1]]);
        $this->po($this->sellerA, 'cancelled', [['type' => 'material', 'name' => 'Cancelled Item', 'price' => 1]]);
        $this->po($this->sellerB, 'ordered', [['type' => 'material', 'name' => 'Other Seller Item', 'price' => 1]]);
        $this->po(null, 'ordered', [['type' => 'material', 'name' => 'Legacy Item', 'price' => 1]]);

        $ids = collect($this->eligible($variant)->assertOk()->json('data'))->pluck('purchase_order_item_id')->sort()->values()->all();

        $this->assertSame(collect($ok)->pluck('id')->sort()->values()->all(), $ids);
    }

    public function test_selector_rows_have_the_documented_shape(): void
    {
        $variant = $this->variantFor($this->sellerA);
        [$item] = $this->po($this->sellerA, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 500, 'qty' => 1000]], 'Vendor X', '2026-09-01 10:00:00', 'PO-SHAPE-1');

        $row = $this->eligible($variant)->assertOk()->json('data.0');

        $this->assertSame($item->id, $row['purchase_order_item_id']);
        $this->assertSame('PO-SHAPE-1', $row['po_number']);
        $this->assertSame('ordered', $row['po_status']);
        $this->assertSame('Vendor X', $row['vendor_name']);
        $this->assertSame('material', $row['line_type']);
        $this->assertSame('Ball Chain', $row['item_name']);
        $this->assertSame('500.00', $row['unit_price']);
        $this->assertEquals(1000, $row['po_qty']);
        $this->assertFalse($row['in_bom']);
        $this->assertArrayHasKey('po_date', $row);
        $this->assertArrayHasKey('vendor_id', $row);
        $this->assertArrayHasKey('material_id', $row);
    }

    public function test_selector_filters_by_text_po_vendor_type_and_date(): void
    {
        $variant = $this->variantFor($this->sellerA);
        [$chain] = $this->po($this->sellerA, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 500]], 'Vendor X', '2026-09-01 10:00:00', 'PO-AAA-1');
        [$assembly] = $this->po($this->sellerA, 'received', [['type' => 'service', 'name' => 'Assembly Service', 'price' => 1000]], 'Vendor Y', '2026-10-01 10:00:00', 'PO-BBB-2');

        $ids = fn (string $q) => collect($this->eligible($variant, $q)->assertOk()->json('data'))->pluck('purchase_order_item_id')->all();

        $this->assertSame([$chain->id], $ids('q=chain'));
        $this->assertSame([$assembly->id], $ids('q=Assembl'), 'service lines match on their description');
        $this->assertSame([$assembly->id], $ids('purchase_order=BBB'));
        $this->assertSame([$chain->id], $ids('vendor_id='.$chain->purchaseOrder->vendor_id));
        $this->assertSame([$assembly->id], $ids('line_type=service'));
        $this->assertSame([$assembly->id], $ids('date_from=2026-09-15'));
        $this->assertSame([$chain->id], $ids('date_to=2026-09-15'));
        $this->assertSame([], $ids('q=nothing-matches'));
    }

    public function test_selector_flags_lines_already_in_this_variants_bom_and_paginates(): void
    {
        $variant = $this->variantFor($this->sellerA);
        $items = $this->po($this->sellerA, 'ordered', collect(range(1, 5))->map(fn ($i) => ['type' => 'material', 'name' => "Mat {$i}", 'price' => $i * 100])->all());
        $this->addItems($variant, [['purchase_order_item_id' => $items[0]->id]])->assertCreated();

        $page = $this->eligible($variant, 'per_page=2')->assertOk();

        $this->assertCount(2, $page->json('data'));
        $this->assertSame(5, $page->json('meta.total'));
        $this->assertSame(3, $page->json('meta.last_page'));

        $all = collect($this->eligible($variant)->json('data'))->keyBy('purchase_order_item_id');
        $this->assertTrue($all[$items[0]->id]['in_bom']);
        $this->assertFalse($all[$items[1]->id]['in_bom']);

        // per_page dibatasi 100.
        $this->assertSame(100, $this->eligible($variant, 'per_page=5000')->json('meta.per_page'));
    }

    public function test_selector_never_shows_the_other_data_mode(): void
    {
        $variant = $this->variantFor($this->sellerA);
        $this->po($this->sellerA, 'ordered', [['type' => 'material', 'name' => 'Live Item', 'price' => 1]]);
        ModeGate::runAs('demo', fn () => $this->po($this->sellerA, 'ordered', [['type' => 'material', 'name' => 'Demo Item', 'price' => 1]]));

        $names = collect($this->eligible($variant)->assertOk()->json('data'))->pluck('item_name')->all();

        $this->assertSame(['Live Item'], $names);
    }

    // ===================================================================
    // Add / update / remove
    // ===================================================================

    public function test_adding_three_lines_snapshots_cost_vendor_and_po_and_totals_the_example(): void
    {
        $variant = $this->variantFor($this->sellerA);
        [$chain] = $this->po($this->sellerA, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 500]], 'Vendor X', null, 'PO-001');
        [$ring] = $this->po($this->sellerA, 'ordered', [['type' => 'material', 'name' => 'Keychain Ring', 'price' => 300]], 'Vendor X', null, 'PO-002');
        [$assembly] = $this->po($this->sellerA, 'received', [['type' => 'service', 'name' => 'Assembly', 'price' => 1000]], 'Vendor Y', null, 'PO-003');

        $response = $this->addItems($variant, [
            ['purchase_order_item_id' => $chain->id],
            ['purchase_order_item_id' => $ring->id, 'qty' => 1],
            ['purchase_order_item_id' => $assembly->id, 'qty' => 1],
        ])->assertCreated();

        $response->assertJsonPath('summary.material_cost', '800.00')
            ->assertJsonPath('summary.service_cost', '1000.00')
            ->assertJsonPath('summary.bom_cost', '1800.00')
            ->assertJsonPath('summary.has_legacy', false);

        $rows = collect($response->json('data'))->keyBy('item_name');
        $this->assertSame('material', $rows['Ball Chain']['line_type']);
        $this->assertSame('PO-001', $rows['Ball Chain']['po_number']);
        $this->assertSame('Vendor X', $rows['Ball Chain']['vendor_name']);
        $this->assertSame('500.00', $rows['Ball Chain']['unit_cost']);
        $this->assertSame('1.0000', $rows['Ball Chain']['qty_needed'], 'quantity defaults to 1');
        $this->assertSame('500.00', $rows['Ball Chain']['item_cost']);
        $this->assertFalse($rows['Ball Chain']['is_legacy']);
        $this->assertSame('service', $rows['Assembly']['line_type']);
        $this->assertNull($rows['Assembly']['material_id']);
        $this->assertSame('Vendor Y', $rows['Assembly']['vendor_name']);
        $this->assertEquals(1000, $rows['Assembly']['po_qty']);
    }

    public function test_the_unit_cost_always_comes_from_the_po_line_never_from_the_client(): void
    {
        $variant = $this->variantFor($this->sellerA);
        [$item] = $this->po($this->sellerA, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 500]]);

        $this->addItems($variant, [['purchase_order_item_id' => $item->id, 'qty' => 2, 'unit_cost' => 1, 'unit_price' => 1]])
            ->assertCreated()->assertJsonPath('data.0.unit_cost', '500.00')->assertJsonPath('summary.bom_cost', '1000.00');
    }

    public function test_a_line_of_another_seller_a_draft_or_cancelled_order_or_an_unknown_line_is_rejected(): void
    {
        $variant = $this->variantFor($this->sellerA);
        [$other] = $this->po($this->sellerB, 'ordered', [['type' => 'material', 'name' => 'B Item', 'price' => 1]]);
        [$draft] = $this->po($this->sellerA, 'draft', [['type' => 'material', 'name' => 'Draft', 'price' => 1]]);
        [$cancelled] = $this->po($this->sellerA, 'cancelled', [['type' => 'material', 'name' => 'Cancelled', 'price' => 1]]);
        [$legacy] = $this->po(null, 'ordered', [['type' => 'material', 'name' => 'Legacy', 'price' => 1]]);

        foreach ([$other->id, $draft->id, $cancelled->id, $legacy->id, 999999] as $id) {
            $this->addItems($variant, [['purchase_order_item_id' => $id]])
                ->assertStatus(422)->assertJsonValidationErrors('items.0.purchase_order_item_id');
        }

        $this->assertSame(0, $variant->bomLines()->count());
    }

    public function test_quantity_must_be_positive_and_at_most_four_decimals(): void
    {
        $variant = $this->variantFor($this->sellerA);
        [$item] = $this->po($this->sellerA, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 500]]);

        foreach ([0, -1, 'abc', 0.00001] as $qty) {
            $this->addItems($variant, [['purchase_order_item_id' => $item->id, 'qty' => $qty]])
                ->assertStatus(422)->assertJsonValidationErrors('items.0.qty');
        }

        $this->addItems($variant, [['purchase_order_item_id' => $item->id, 'qty' => 0.0525]])
            ->assertCreated()->assertJsonPath('data.0.qty_needed', '0.0525');
    }

    public function test_the_same_po_line_cannot_be_added_twice_and_a_failed_batch_adds_nothing(): void
    {
        $variant = $this->variantFor($this->sellerA);
        [$a, $b] = $this->po($this->sellerA, 'ordered', [
            ['type' => 'material', 'name' => 'Ball Chain', 'price' => 500],
            ['type' => 'material', 'name' => 'Ring', 'price' => 300],
        ]);
        $this->addItems($variant, [['purchase_order_item_id' => $a->id]])->assertCreated();

        // duplikat yang sudah ada -> 409
        $this->addItems($variant, [['purchase_order_item_id' => $a->id]])->assertStatus(409);

        // baris valid + baris duplikat dalam satu permintaan -> tidak ada yang masuk
        $this->addItems($variant, [['purchase_order_item_id' => $b->id], ['purchase_order_item_id' => $a->id]])->assertStatus(409);
        $this->assertSame(1, $variant->bomLines()->count());
        $this->assertSame(1, ActivityLog::where('action', 'bom_item_added')->count(), 'a rolled-back batch leaves no audit rows');

        // duplikat di dalam permintaan yang sama -> 422
        $this->addItems($variant, [['purchase_order_item_id' => $b->id], ['purchase_order_item_id' => $b->id]])->assertStatus(422);
        $this->assertSame(1, $variant->bomLines()->count());
    }

    public function test_two_po_lines_of_the_same_material_at_different_prices_can_both_be_used(): void
    {
        $variant = $this->variantFor($this->sellerA);
        [$cheap] = $this->po($this->sellerA, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 500]], 'Vendor X');
        [$dear] = $this->po($this->sellerA, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 700]], 'Vendor Z');

        $this->addItems($variant, [['purchase_order_item_id' => $cheap->id], ['purchase_order_item_id' => $dear->id]])
            ->assertCreated()->assertJsonPath('summary.bom_cost', '1200.00');
    }

    public function test_updating_changes_quantity_and_notes_only_and_never_the_recorded_cost(): void
    {
        $variant = $this->variantFor($this->sellerA);
        [$item] = $this->po($this->sellerA, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 500]]);
        $rowId = $this->addItems($variant, [['purchase_order_item_id' => $item->id]])->json('data.0.id');

        $this->putJson("/api/v1/bom/{$rowId}", ['qty_needed' => 3, 'notes' => 'dua cadangan', 'unit_cost' => 1])
            ->assertOk()->assertJsonPath('data.0.qty_needed', '3.0000')->assertJsonPath('data.0.unit_cost', '500.00')
            ->assertJsonPath('summary.bom_cost', '1500.00');

        foreach ([0, -2, 'x'] as $bad) {
            $this->putJson("/api/v1/bom/{$rowId}", ['qty_needed' => $bad])->assertStatus(422)->assertJsonValidationErrors('qty_needed');
        }
        $this->assertSame('dua cadangan', ProductVariantBomLine::find($rowId)->notes);
    }

    public function test_removing_a_row_updates_the_total_and_leaves_the_purchase_order_untouched(): void
    {
        $variant = $this->variantFor($this->sellerA);
        [$a, $b] = $this->po($this->sellerA, 'ordered', [
            ['type' => 'material', 'name' => 'Ball Chain', 'price' => 500],
            ['type' => 'service', 'name' => 'Assembly', 'price' => 1000],
        ]);
        $rows = $this->addItems($variant, [['purchase_order_item_id' => $a->id], ['purchase_order_item_id' => $b->id]])->json('data');

        $this->deleteJson('/api/v1/bom/'.$rows[0]['id'])->assertOk()->assertJsonPath('summary.bom_cost', '1000.00');

        $this->assertSame(1, $variant->bomLines()->count());
        $this->assertNotNull(PurchaseOrderItem::find($a->id));
        $this->assertSame('500.00', number_format((float) PurchaseOrderItem::find($a->id)->unit_price, 2, '.', ''));
    }

    // ===================================================================
    // Snapshot, authorization, audit
    // ===================================================================

    public function test_a_recorded_cost_survives_a_price_change_and_a_cancelled_source_order(): void
    {
        $variant = $this->variantFor($this->sellerA);
        [$item] = $this->po($this->sellerA, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 500]], 'Vendor X', null, 'PO-SNAP');
        $this->addItems($variant, [['purchase_order_item_id' => $item->id]])->assertCreated();

        $item->update(['unit_price' => 900]);
        $item->purchaseOrder->update(['status' => 'cancelled']);
        $item->purchaseOrder->vendor->update(['name' => 'Renamed Vendor']);

        $this->getJson("/api/v1/variants/{$variant->id}/bom")->assertOk()
            ->assertJsonPath('data.0.unit_cost', '500.00')
            ->assertJsonPath('data.0.vendor_name', 'Vendor X')
            ->assertJsonPath('data.0.po_number', 'PO-SNAP')
            ->assertJsonPath('summary.bom_cost', '500.00');
    }

    public function test_reading_needs_products_but_every_mutation_and_the_selector_also_need_purchase_orders(): void
    {
        $variant = $this->variantFor($this->sellerA);
        [$item] = $this->po($this->sellerA, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 500]]);
        $rowId = $this->addItems($variant, [['purchase_order_item_id' => $item->id]])->json('data.0.id');

        $productsOnly = User::factory()->create(['role_id' => Role::factory()->create(['name' => 'Staf Produk', 'menu_keys' => ['products']])->id]);
        $this->actingAs($productsOnly, 'sanctum');

        $this->getJson("/api/v1/variants/{$variant->id}/bom")->assertOk();
        $this->eligible($variant)->assertForbidden();
        $this->addItems($variant, [['purchase_order_item_id' => $item->id]])->assertForbidden();
        $this->putJson("/api/v1/bom/{$rowId}", ['qty_needed' => 2])->assertForbidden();
        $this->deleteJson("/api/v1/bom/{$rowId}")->assertForbidden();

        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');
        $this->getJson("/api/v1/variants/{$variant->id}/bom")->assertForbidden();
        $this->eligible($variant)->assertForbidden();
        $this->addItems($variant, [['purchase_order_item_id' => $item->id]])->assertForbidden();
    }

    public function test_every_mutation_writes_its_audit_row_with_who_and_old_new_values(): void
    {
        $variant = $this->variantFor($this->sellerA);
        [$item] = $this->po($this->sellerA, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 500]]);

        $rowId = $this->addItems($variant, [['purchase_order_item_id' => $item->id, 'qty' => 2]])->json('data.0.id');
        $this->putJson("/api/v1/bom/{$rowId}", ['qty_needed' => 5])->assertOk();
        $this->deleteJson("/api/v1/bom/{$rowId}")->assertOk();

        $added = ActivityLog::where('action', 'bom_item_added')->firstOrFail();
        $this->assertSame($this->owner->id, $added->user_id);
        $this->assertSame($rowId, $added->entity_id);
        $this->assertSame('ProductVariantBomLine', $added->entity_type);

        $changed = ActivityLog::where('action', 'bom_qty_changed')->firstOrFail();
        $this->assertEquals(2, $changed->old_values['qty_needed']);
        $this->assertEquals(5, $changed->new_values['qty_needed']);

        $removed = ActivityLog::where('action', 'bom_item_removed')->firstOrFail();
        $this->assertSame($rowId, $removed->entity_id);
    }
}
