<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\MaterialOutletStock;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MaterialReportFixtures;
use Tests\TestCase;

/**
 * 088 US2 (T018) — POST /reports/materials/purchase-drafts (contracts §4, keputusan pemilik Q2 = B): satu draf PO
 * per vendor rujukan, outlet = outlet TUNGGAL dari server (bukan body), baris tanpa vendor ditolak per baris, stok
 * tidak berubah, log PO tertulis lewat service yang ada, idempoten.
 */
class MaterialPurchaseDraftsTest extends TestCase
{
    use MaterialReportFixtures, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMaterialReportWorld();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
    }

    private function draft(array $lines, ?int $outlet, array $headers = [], array $extra = [])
    {
        $req = $this->flushHeaders()->withHeaders($headers + ($outlet ? ['X-Outlet-Id' => (string) $outlet] : []));

        return $req->postJson('/api/v1/reports/materials/purchase-drafts', ['lines' => $lines] + $extra);
    }

    public function test_one_draft_per_vendor_for_the_single_outlet_and_vendorless_rows_are_refused(): void
    {
        $before = MaterialOutletStock::where(['material_id' => $this->mat['soto']->id, 'outlet_id' => $this->outletA->id])->value('current_stock');

        $res = $this->draft([
            ['material_id' => $this->mat['soto']->id, 'qty' => 22],
            ['material_id' => $this->mat['telur']->id, 'qty' => 5.5],
            ['material_id' => $this->mat['ayam']->id, 'qty' => 16],
        ], $this->outletA->id, [], ['outlet_id' => $this->outletB->id])->assertCreated();

        $this->assertCount(2, $res->json('created'));
        $this->assertSame([['material_id' => $this->mat['ayam']->id, 'reason' => 'no_vendor']], $res->json('refused'));

        $pos = PurchaseOrder::with('items')->get();
        $this->assertSame(['draft'], $pos->pluck('status')->unique()->values()->all());
        $this->assertSame([$this->outletA->id], $pos->pluck('outlet_id')->unique()->values()->all());   // body outlet_id diabaikan
        $soto = $pos->firstWhere('vendor_id', $this->grosir->id);
        $this->assertSame(1, $soto->items->count());
        $this->assertEquals(22, (float) $soto->items[0]->qty);
        $this->assertEquals(3000, (float) $soto->items[0]->unit_price);
        $this->assertSame('material', $soto->items[0]->line_type);
        $this->assertEquals(66000, (float) $soto->total_amount);

        $this->assertSame($before, MaterialOutletStock::where(['material_id' => $this->mat['soto']->id, 'outlet_id' => $this->outletA->id])->value('current_stock'));
        $this->assertSame(2, ActivityLog::where(['action' => 'created', 'entity_type' => 'PurchaseOrder'])->count());
    }

    public function test_without_a_single_outlet_it_asks_to_pick_one(): void
    {
        $this->draft([['material_id' => $this->mat['soto']->id, 'qty' => 1]], null)
            ->assertStatus(422)->assertJsonValidationErrors('outlet');
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_all_refused_is_422_and_inactive_or_unknown_materials_are_refused(): void
    {
        $this->mat['telur']->update(['is_active' => false]);

        $res = $this->draft([
            ['material_id' => $this->mat['ayam']->id, 'qty' => 1],
            ['material_id' => $this->mat['telur']->id, 'qty' => 1],
            ['material_id' => 999999, 'qty' => 1],
        ], $this->outletA->id)->assertStatus(422);

        $this->assertEqualsCanonicalizing(['no_vendor', 'inactive_material', 'not_visible'], array_column($res->json('refused'), 'reason'));
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_quantities_are_validated(): void
    {
        $this->draft([['material_id' => $this->mat['soto']->id, 'qty' => 0]], $this->outletA->id)->assertStatus(422)->assertJsonValidationErrors('lines.0.qty');
        $this->draft([], $this->outletA->id)->assertStatus(422)->assertJsonValidationErrors('lines');
    }

    public function test_the_same_idempotency_key_never_creates_twice(): void
    {
        $lines = [['material_id' => $this->mat['soto']->id, 'qty' => 3]];
        $first = $this->draft($lines, $this->outletA->id, ['Idempotency-Key' => 'mat-draft-1'])->assertCreated();
        $again = $this->draft($lines, $this->outletA->id, ['Idempotency-Key' => 'mat-draft-1'])->assertCreated();

        $this->assertSame($first->json('created'), $again->json('created'));
        $this->assertSame(1, PurchaseOrder::count());
    }

    public function test_it_needs_both_the_reports_menu_and_the_right_to_create_purchase_orders(): void
    {
        $reportsOnly = Role::factory()->create(['menu_keys' => ['reports']]);
        $this->actingAs(User::factory()->create(['role' => 'cashier', 'role_id' => $reportsOnly->id]), 'sanctum');
        $this->draft([['material_id' => $this->mat['soto']->id, 'qty' => 1]], $this->outletA->id)->assertForbidden();

        $this->actingAs($this->cashier, 'sanctum');
        $this->draft([['material_id' => $this->mat['soto']->id, 'qty' => 1]], $this->outletA->id)->assertForbidden();
        $this->assertSame(0, PurchaseOrder::count());
    }
}
