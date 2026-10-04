<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Artist;
use App\Models\ProductVariant;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBomFixtures;
use Tests\TestCase;

/**
 * 034-seller-po-bom (US5) — riwayat biaya tetap akurat: baris tidak pernah
 * menghitung ulang dirinya sendiri; harga yang lebih baru dan PO sumber yang
 * dibatalkan hanyalah ISYARAT (dihitung saat dibaca); mengganti sumber adalah
 * tindakan eksplisit; setiap perubahan tercatat.
 */
class VariantBomHistoryTest extends TestCase
{
    use BuildsBomFixtures, RefreshDatabase;

    private User $owner;

    private Artist $seller;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
        $this->seller = Artist::factory()->create();
        $this->variant = $this->variantFor($this->seller);
    }

    private function bom(): array
    {
        return $this->getJson("/api/v1/variants/{$this->variant->id}/bom")->assertOk()->json();
    }

    /** Baris BOM dari satu baris PO; mengembalikan [id baris BOM, baris PO]. */
    private function useLine(string $type, string $name, float $price, string $orderedAt, string $status = 'ordered', ?Artist $artist = null): array
    {
        [$item] = $this->po($artist ?? $this->seller, $status, [['type' => $type, 'name' => $name, 'price' => $price]], 'Vendor X', $orderedAt);
        $rowId = $this->addItems($this->variant, [['purchase_order_item_id' => $item->id]])->assertCreated()->json('data.0.id');

        return [$rowId, $item];
    }

    private function row(string $name): array
    {
        return collect($this->bom()['data'])->firstWhere('item_name', $name);
    }

    // ===================================================================
    // Cues
    // ===================================================================

    public function test_a_newer_purchase_order_at_a_different_price_shows_a_cue_but_never_changes_the_row(): void
    {
        $this->useLine('material', 'Ball Chain', 500, '2026-09-01 10:00:00');
        [$newer] = $this->po($this->seller, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 600]], 'Vendor Z', '2026-10-01 10:00:00', 'PO-NEW');

        $row = $this->row('Ball Chain');

        $this->assertSame('500.00', $row['unit_cost']);
        $this->assertSame(['purchase_order_item_id' => $newer->id, 'po_number' => 'PO-NEW', 'unit_price' => '600.00'], $row['newer_price']);
        $this->assertFalse($row['source_cancelled']);
        $this->assertSame('500.00', $this->bom()['summary']['bom_cost']);
    }

    public function test_no_cue_for_the_same_price_an_older_order_another_seller_or_ineligible_orders(): void
    {
        $this->useLine('material', 'Ball Chain', 500, '2026-09-01 10:00:00');

        $this->po($this->seller, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 500]], 'Vendor Z', '2026-10-01 10:00:00');  // harga sama
        $this->po($this->seller, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 400]], 'Vendor Z', '2026-08-01 10:00:00');  // lebih lama
        $this->po(Artist::factory()->create(), 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 900]], 'Vendor Z', '2026-10-02 10:00:00'); // seller lain
        $this->po($this->seller, 'draft', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 901]], 'Vendor Z', '2026-10-03 10:00:00');
        $this->po($this->seller, 'cancelled', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 902]], 'Vendor Z', '2026-10-04 10:00:00');
        $this->po($this->seller, 'ordered', [['type' => 'material', 'name' => 'Other Material', 'price' => 903]], 'Vendor Z', '2026-10-05 10:00:00'); // bahan lain

        $this->assertNull($this->row('Ball Chain')['newer_price']);
    }

    public function test_only_the_latest_newer_line_counts_for_the_cue(): void
    {
        $this->useLine('material', 'Ball Chain', 500, '2026-09-01 10:00:00');
        $this->po($this->seller, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 600]], 'Vendor Z', '2026-10-01 10:00:00');
        [$latest] = $this->po($this->seller, 'paid', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 700]], 'Vendor Z', '2026-10-10 10:00:00');

        $this->assertSame($latest->id, $this->row('Ball Chain')['newer_price']['purchase_order_item_id']);
        $this->assertSame('700.00', $this->row('Ball Chain')['newer_price']['unit_price']);
    }

    public function test_service_lines_match_on_their_trimmed_case_insensitive_description(): void
    {
        $this->useLine('service', 'Assembly', 1000, '2026-09-01 10:00:00');
        $this->po($this->seller, 'ordered', [['type' => 'service', 'name' => '  assembly ', 'price' => 1200]], 'Vendor Y', '2026-10-01 10:00:00');

        $this->assertSame('1200.00', $this->row('Assembly')['newer_price']['unit_price']);
    }

    public function test_cues_are_computed_with_a_constant_number_of_queries(): void
    {
        $this->useLine('material', 'Mat 1', 100, '2026-09-01 10:00:00');
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson("/api/v1/variants/{$this->variant->id}/bom")->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        $count(); // pemanasan (cache role/menu)
        $one = $count();

        foreach (range(2, 6) as $i) {
            $this->useLine('material', "Mat {$i}", 100 * $i, '2026-09-01 10:00:00');
            $this->po($this->seller, 'ordered', [['type' => 'material', 'name' => "Mat {$i}", 'price' => 999]], 'Vendor Z', '2026-10-01 10:00:00');
        }

        $this->assertSame($one, $count(), 'query count must not grow with the number of rows');
    }

    public function test_a_cancelled_source_order_flags_the_row_and_keeps_its_cost(): void
    {
        [, $item] = $this->useLine('material', 'Ball Chain', 500, '2026-09-01 10:00:00');
        $this->putJson("/api/v1/purchase-orders/{$item->purchaseOrder->id}", ['notes' => 'x'])->assertOk(); // PO tetap bisa disunting

        $this->patchJson("/api/v1/purchase-orders/{$item->purchaseOrder->id}/status", ['status' => 'cancelled', 'cancel_reason' => 'salah pesan'])->assertOk();

        $row = $this->row('Ball Chain');
        $this->assertTrue($row['source_cancelled']);
        $this->assertSame('500.00', $row['unit_cost']);
        $this->assertSame('500.00', $this->bom()['summary']['bom_cost']);
    }

    public function test_a_purchase_order_that_feeds_a_bom_cannot_be_deleted(): void
    {
        [, $item] = $this->useLine('material', 'Ball Chain', 500, '2026-09-01 10:00:00');

        $this->deleteJson("/api/v1/purchase-orders/{$item->purchaseOrder->id}")->assertStatus(409);

        $this->assertNotNull(PurchaseOrderItem::find($item->id));
        $this->assertCount(1, $this->bom()['data']);
    }

    // ===================================================================
    // Replace source
    // ===================================================================

    private function replace(int $rowId, int $itemId)
    {
        return $this->postJson("/api/v1/bom/{$rowId}/replace-source", ['purchase_order_item_id' => $itemId]);
    }

    public function test_replacing_the_source_re_snapshots_cost_vendor_and_po_keeps_the_quantity_and_audits(): void
    {
        [$rowId] = $this->useLine('material', 'Ball Chain', 500, '2026-09-01 10:00:00');
        $this->putJson("/api/v1/bom/{$rowId}", ['qty_needed' => 3])->assertOk();
        [$newer] = $this->po($this->seller, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 600]], 'Vendor Z', '2026-10-01 10:00:00', 'PO-NEW');

        $res = $this->replace($rowId, $newer->id)->assertOk()
            ->assertJsonPath('data.0.unit_cost', '600.00')->assertJsonPath('data.0.po_number', 'PO-NEW')
            ->assertJsonPath('data.0.vendor_name', 'Vendor Z')->assertJsonPath('data.0.qty_needed', '3.0000')
            ->assertJsonPath('data.0.newer_price', null)->assertJsonPath('summary.bom_cost', '1800.00');
        $this->assertSame($newer->id, $res->json('data.0.purchase_order_item_id'));

        $log = ActivityLog::where('action', 'bom_source_replaced')->firstOrFail();
        $this->assertSame($rowId, $log->entity_id);
        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertSame('500.00', $log->old_values['unit_cost']);
        $this->assertSame('600.00', $log->new_values['unit_cost']);
        $this->assertSame('PO-NEW', $log->new_values['po_number']);
    }

    public function test_replace_applies_the_same_seller_and_status_rules_and_rejects_duplicates(): void
    {
        [$rowId] = $this->useLine('material', 'Ball Chain', 500, '2026-09-01 10:00:00');
        [$otherSeller] = $this->po(Artist::factory()->create(), 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 1]]);
        [$draft] = $this->po($this->seller, 'draft', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 1]]);
        [$cancelled] = $this->po($this->seller, 'cancelled', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 1]]);

        foreach ([$otherSeller->id, $draft->id, $cancelled->id, 999999] as $id) {
            $this->replace($rowId, $id)->assertStatus(422);
        }

        [, $second] = $this->useLine('material', 'Ring', 300, '2026-09-02 10:00:00');
        $this->replace($rowId, $second->id)->assertStatus(409);   // sudah dipakai baris lain BOM ini
        $this->assertSame('500.00', $this->row('Ball Chain')['unit_cost']);
        $this->assertSame(0, ActivityLog::where('action', 'bom_source_replaced')->count());
    }

    public function test_replacing_with_the_same_source_changes_nothing_and_logs_nothing(): void
    {
        [$rowId, $item] = $this->useLine('material', 'Ball Chain', 500, '2026-09-01 10:00:00');

        $this->replace($rowId, $item->id)->assertOk()->assertJsonPath('data.0.unit_cost', '500.00');

        $this->assertSame(0, ActivityLog::where('action', 'bom_source_replaced')->count());
    }

    public function test_replacing_the_source_of_a_complete_bom_resyncs_the_cost_price(): void
    {
        [$rowId] = $this->useLine('material', 'Ball Chain', 500, '2026-09-01 10:00:00');
        $this->postJson("/api/v1/variants/{$this->variant->id}/bom/complete")->assertOk();
        [$newer] = $this->po($this->seller, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 650]], 'Vendor Z', '2026-10-01 10:00:00');

        $this->replace($rowId, $newer->id)->assertOk()->assertJsonPath('summary.cost_price', '650.00');

        $this->assertSame('650.00', number_format((float) $this->variant->fresh()->cost_price, 2, '.', ''));
    }

    public function test_replace_needs_both_menus(): void
    {
        [$rowId] = $this->useLine('material', 'Ball Chain', 500, '2026-09-01 10:00:00');
        [$newer] = $this->po($this->seller, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 600]], 'Vendor Z', '2026-10-01 10:00:00');

        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');
        $this->replace($rowId, $newer->id)->assertForbidden();
    }

    // ===================================================================
    // Audit trail
    // ===================================================================

    public function test_a_whole_scenario_leaves_a_complete_audit_trail_with_who_when_and_old_new_values(): void
    {
        [$rowId] = $this->useLine('material', 'Ball Chain', 500, '2026-09-01 10:00:00');
        [$newer] = $this->po($this->seller, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 600]], 'Vendor Z', '2026-10-01 10:00:00');
        $sibling = $this->variant->product->variants()->create(['sku' => $this->variant->sku.'S', 'variant_name' => 'Blue', 'sell_price' => 1, 'cost_price' => 0, 'current_stock' => 0]);

        $this->putJson("/api/v1/bom/{$rowId}", ['qty_needed' => 2])->assertOk();
        $this->replace($rowId, $newer->id)->assertOk();
        $this->postJson("/api/v1/variants/{$this->variant->id}/bom/copy-out", ['mode' => 'next'])->assertOk();
        $this->postJson("/api/v1/variants/{$this->variant->id}/bom/complete")->assertOk();
        $this->postJson("/api/v1/variants/{$this->variant->id}/bom/reopen")->assertOk();

        $actions = ActivityLog::orderBy('id')->pluck('action')->all();
        foreach (['bom_item_added', 'bom_qty_changed', 'bom_source_replaced', 'bom_copied', 'bom_completed', 'cost_price_synced', 'bom_reopened'] as $expected) {
            $this->assertContains($expected, $actions, $expected);
        }

        foreach (ActivityLog::whereIn('action', ['bom_item_added', 'bom_qty_changed', 'bom_source_replaced', 'bom_copied', 'bom_completed', 'bom_reopened'])->get() as $log) {
            $this->assertSame($this->owner->id, $log->user_id, $log->action.' has a user');
            $this->assertNotNull($log->created_at, $log->action.' has a time');
        }
        $this->assertNotNull(ActivityLog::where('action', 'bom_qty_changed')->first()->old_values);
        $this->assertNotNull(ActivityLog::where('action', 'bom_qty_changed')->first()->new_values);
    }
}
