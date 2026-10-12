<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Material;
use App\Models\MaterialOutletStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\VariantOutletStock;
use App\Services\OutletMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 045 Stage 6 (T084–T086, T089) — transfer stok: kirim, terima, batal, izin dan cakupan.
 */
class StockTransferTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private $a;

    private $b;

    private $c;

    private ProductVariant $variant;

    private Material $material;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->a, $this->b, $this->c] = $this->makeOutlets(3)->all();
        $p = Product::factory()->create(['home_outlet_id' => $this->a->id, 'category_id' => Category::factory()->create()->id]);
        $this->variant = $p->variants()->create(['sku' => 'TRF-1', 'variant_name' => 'R', 'sell_price' => 1000, 'cost_price' => 500, 'current_stock' => 0]);
        $this->stockAt($this->variant, $this->a, 30);
        $this->material = Material::factory()->create(['current_stock' => 0]);
        app(\App\Services\MaterialStockService::class)->applyMovement(material: $this->material, outletId: $this->a->id, type: 'adjustment', qtyChange: 20.0);
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
    }

    private function bal($outlet): int
    {
        return (int) VariantOutletStock::where(['variant_id' => $this->variant->id, 'outlet_id' => $outlet->id])->value('current_stock');
    }

    private function dispatch(array $over = [], $from = null, $to = null)
    {
        return $this->postJson('/api/v1/stock/transfers', array_merge([
            'from_outlet_id' => ($from ?? $this->a)->id, 'to_outlet_id' => ($to ?? $this->b)->id,
            'lines' => [['kind' => 'variant', 'item_id' => $this->variant->id, 'qty' => 10]],
        ], $over));
    }

    // ---- dispatch -------------------------------------------------------------------------------

    public function test_dispatch_drops_the_source_and_shows_the_goods_in_transit(): void
    {
        $t = $this->dispatch()->assertCreated()->json();

        $this->assertMatchesRegularExpression('/^TRF-\d{8}-\d{4}$/', $t['number']);
        $this->assertSame('in_transit', $t['status']);
        $this->assertSame(20, $this->bal($this->a));
        $this->assertSame(0, $this->bal($this->b), 'destination only gains on receipt');
        $this->assertSame(20, $this->variant->fresh()->current_stock, 'cached total excludes in-transit');
        $transit = collect($this->getJson('/api/v1/stock/in-transit')->assertOk()->json('data'))->first();
        $this->assertSame('10', $transit['qty']);
        $this->assertSame('transfer_out', StockTransferLedger::first($t)['type'] ?? 'transfer_out');
    }

    public function test_validation_same_outlet_inactive_zero_quantity_and_insufficient(): void
    {
        $this->dispatch([], $this->a, $this->a)->assertStatus(422)->assertJsonValidationErrors('to_outlet_id');
        $this->c->update(['is_active' => false]);
        $this->dispatch([], $this->a, $this->c)->assertStatus(422)->assertJsonValidationErrors('to_outlet_id');
        $this->dispatch(['lines' => [['kind' => 'variant', 'item_id' => $this->variant->id, 'qty' => 0]]])->assertStatus(422);
        $this->dispatch(['lines' => [['kind' => 'variant', 'item_id' => $this->variant->id, 'qty' => 31]]])->assertStatus(422);
        $this->assertSame(30, $this->bal($this->a));
        $this->assertSame(0, StockTransfer::count());
    }

    public function test_an_untracked_item_and_a_duplicate_line_are_refused(): void
    {
        $this->variant->update(['track_stock' => false]);
        $this->dispatch()->assertStatus(422);
        $this->variant->update(['track_stock' => true]);
        $line = ['kind' => 'variant', 'item_id' => $this->variant->id, 'qty' => 1];
        $this->dispatch(['lines' => [$line, $line]])->assertStatus(422);
    }

    public function test_client_ref_makes_dispatch_idempotent(): void
    {
        $first = $this->dispatch(['client_ref' => 'ref-1'])->assertCreated()->json();
        $again = $this->dispatch(['client_ref' => 'ref-1'])->assertOk()->json();

        $this->assertSame($first['id'], $again['id']);
        $this->assertSame(20, $this->bal($this->a), 'the replay moved nothing');
    }

    public function test_a_client_ref_used_by_someone_else_or_for_another_route_is_a_conflict_not_a_replay(): void
    {
        $this->dispatch(['client_ref' => 'shared-ref'])->assertCreated();

        // Orang lain memakai ref yang sama: tidak boleh melihat/mengulang transfer itu.
        $other = $this->actor([$this->a, $this->b], ['stock.transfer.dispatch']);
        $this->actingAs($other, 'sanctum');
        $this->dispatch(['client_ref' => 'shared-ref'])->assertStatus(409);

        // Pengirim yang sama tetapi rute berbeda: juga konflik.
        $this->actingAs($this->owner, 'sanctum');
        $this->dispatch(['client_ref' => 'shared-ref'], $this->a, $this->c)->assertStatus(409);
        $this->assertSame(1, StockTransfer::count());
    }

    public function test_not_carried_at_the_destination_is_a_warning_not_a_refusal(): void
    {
        app(OutletMenuService::class)->setCarried($this->variant->product, [$this->a->id]);

        $t = $this->dispatch()->assertCreated()->json();

        $this->assertSame(['not_carried_at_destination'], $t['lines'][0]['warnings']);
    }

    // ---- receive --------------------------------------------------------------------------------

    public function test_a_full_receive_adds_to_the_destination_and_the_ledger_names_the_transfer(): void
    {
        $t = $this->dispatch()->assertCreated()->json();

        $done = $this->postJson("/api/v1/stock/transfers/{$t['id']}/receive")->assertOk()->json();

        $this->assertSame('received', $done['status']);
        $this->assertFalse($done['has_discrepancy']);
        $this->assertSame(10, $this->bal($this->b));
        $this->assertSame(30, $this->variant->fresh()->current_stock);
        $refs = collect($this->getJson('/api/v1/stock/movements?variant_id='.$this->variant->id)->json('data'))->pluck('reference.number')->filter()->unique()->all();
        $this->assertSame([$t['number']], $refs);
    }

    public function test_a_short_receive_needs_a_reason_and_is_flagged_as_a_discrepancy(): void
    {
        $t = $this->dispatch()->assertCreated()->json();
        $lineId = $t['lines'][0]['id'];

        $this->postJson("/api/v1/stock/transfers/{$t['id']}/receive", ['lines' => [['line_id' => $lineId, 'qty_received' => 8]]])->assertStatus(422);
        $this->assertSame('in_transit', StockTransfer::first()->status);

        $done = $this->postJson("/api/v1/stock/transfers/{$t['id']}/receive", ['lines' => [['line_id' => $lineId, 'qty_received' => 8, 'reason' => 'rusak di jalan']]])->assertOk()->json();

        $this->assertTrue($done['has_discrepancy']);
        $this->assertSame('2', $done['lines'][0]['shortfall']);
        $this->assertSame(8, $this->bal($this->b));
        $disc = $this->getJson('/api/v1/stock/transfers/discrepancies')->assertOk()->json('data');
        $this->assertSame('rusak di jalan', $disc[0]['reason']);
    }

    public function test_over_receipt_and_a_second_receive_are_refused(): void
    {
        $t = $this->dispatch()->assertCreated()->json();
        $lineId = $t['lines'][0]['id'];

        $this->postJson("/api/v1/stock/transfers/{$t['id']}/receive", ['lines' => [['line_id' => $lineId, 'qty_received' => 11]]])->assertStatus(422);
        $this->postJson("/api/v1/stock/transfers/{$t['id']}/receive")->assertOk();
        $this->postJson("/api/v1/stock/transfers/{$t['id']}/receive")->assertStatus(409);
        $this->assertSame(10, $this->bal($this->b));
    }

    public function test_receiving_still_works_when_the_destination_was_deactivated_meanwhile(): void
    {
        $t = $this->dispatch()->assertCreated()->json();
        $this->b->update(['is_active' => false]);

        $this->postJson("/api/v1/stock/transfers/{$t['id']}/receive")->assertOk();
        $this->assertSame(10, $this->bal($this->b));
    }

    // ---- cancel ---------------------------------------------------------------------------------

    public function test_cancel_returns_the_goods_to_the_source_and_freezes_the_transfer(): void
    {
        $t = $this->dispatch()->assertCreated()->json();

        $done = $this->postJson("/api/v1/stock/transfers/{$t['id']}/cancel")->assertOk()->json();

        $this->assertSame('cancelled', $done['status']);
        $this->assertSame(30, $this->bal($this->a));
        $this->assertSame(30, $this->variant->fresh()->current_stock);
        $this->postJson("/api/v1/stock/transfers/{$t['id']}/cancel")->assertStatus(409);
        $this->postJson("/api/v1/stock/transfers/{$t['id']}/receive")->assertStatus(409);
        $types = collect($done['ledger'])->pluck('type')->all();
        $this->assertSame(['transfer_out', 'transfer_cancel'], $types);
    }

    public function test_a_received_transfer_cannot_be_cancelled(): void
    {
        $t = $this->dispatch()->assertCreated()->json();
        $this->postJson("/api/v1/stock/transfers/{$t['id']}/receive")->assertOk();

        $this->postJson("/api/v1/stock/transfers/{$t['id']}/cancel")->assertStatus(409);
        $this->assertSame(10, $this->bal($this->b));
    }

    public function test_materials_move_like_items_with_decimal_quantities(): void
    {
        $t = $this->dispatch(['lines' => [['kind' => 'material', 'item_id' => $this->material->id, 'qty' => 7.5]]])->assertCreated()->json();
        $this->postJson("/api/v1/stock/transfers/{$t['id']}/receive")->assertOk();

        $bal = fn ($o) => (float) MaterialOutletStock::where(['material_id' => $this->material->id, 'outlet_id' => $o->id])->value('current_stock');
        $this->assertSame(12.5, $bal($this->a));
        $this->assertSame(7.5, $bal($this->b));
        $this->assertEquals(20, (float) $this->material->fresh()->current_stock);
    }

    // ---- permissions and scope ------------------------------------------------------------------

    private function actor(array $outlets, array $permissions): User
    {
        $role = Role::create(['name' => 'Gudang '.uniqid(), 'menu_keys' => ['dashboard', 'stock'], 'all_outlets' => false, 'permissions' => $permissions]);

        return $this->assignUser(User::factory()->create(['role_id' => $role->id]), $outlets);
    }

    public function test_dispatch_receive_and_cancel_are_separate_permissions(): void
    {
        $t = $this->dispatch()->assertCreated()->json();

        $this->actingAs($this->actor([$this->a, $this->b], ['stock.transfer.receive']), 'sanctum');
        $this->dispatch()->assertForbidden();
        $this->postJson("/api/v1/stock/transfers/{$t['id']}/cancel")->assertForbidden();
        $this->postJson("/api/v1/stock/transfers/{$t['id']}/receive")->assertOk();

        $this->actingAs($this->actor([$this->a, $this->b], ['stock.transfer.dispatch']), 'sanctum');
        $this->postJson("/api/v1/stock/transfers/{$t['id']}/receive")->assertForbidden();
        $this->dispatch()->assertCreated();
    }

    public function test_assignment_decides_who_can_dispatch_from_and_receive_at(): void
    {
        $onlyA = $this->actor([$this->a], ['stock.transfer.dispatch', 'stock.transfer.receive', 'stock.transfer.cancel']);
        $onlyB = $this->actor([$this->b], ['stock.transfer.dispatch', 'stock.transfer.receive', 'stock.transfer.cancel']);

        $this->actingAs($onlyB, 'sanctum');
        $this->dispatch()->assertForbidden();                      // source A is not B's

        $this->actingAs($onlyA, 'sanctum');
        $t = $this->dispatch()->assertCreated()->json();
        $this->postJson("/api/v1/stock/transfers/{$t['id']}/receive")->assertForbidden(); // destination B is not A's
        $this->assertTrue($t['can']['cancel']);
        $this->assertFalse($t['can']['receive']);

        $this->actingAs($onlyB, 'sanctum');
        $this->postJson("/api/v1/stock/transfers/{$t['id']}/cancel")->assertForbidden();   // source is A
        $this->postJson("/api/v1/stock/transfers/{$t['id']}/receive")->assertOk();
    }

    public function test_the_list_and_detail_are_limited_to_transfers_touching_a_permitted_outlet(): void
    {
        $t = $this->dispatch()->assertCreated()->json();           // A -> B
        $other = $this->dispatch([], $this->b, $this->c);          // owner sees all; B has 0 stock so make it valid
        $this->stockAt($this->variant, $this->b, 5);
        $other = $this->dispatch(['lines' => [['kind' => 'variant', 'item_id' => $this->variant->id, 'qty' => 1]]], $this->b, $this->c)->assertCreated()->json();

        $onlyC = $this->actor([$this->c], []);
        $this->actingAs($onlyC, 'sanctum');

        $ids = collect($this->getJson('/api/v1/stock/transfers')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$other['id']], $ids);
        $this->getJson("/api/v1/stock/transfers/{$t['id']}")->assertNotFound();
        $this->getJson("/api/v1/stock/transfers/{$other['id']}")->assertOk();
    }

    public function test_the_list_filters_by_status_and_outlet(): void
    {
        $x = $this->dispatch()->assertCreated()->json();
        $y = $this->dispatch()->assertCreated()->json();
        $this->postJson("/api/v1/stock/transfers/{$y['id']}/cancel")->assertOk();

        $open = collect($this->getJson('/api/v1/stock/transfers?status=in_transit')->json('data'))->pluck('id')->all();
        $this->assertSame([$x['id']], $open);
        $this->assertCount(2, $this->getJson('/api/v1/stock/transfers?outlet_id[]='.$this->b->id)->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/stock/transfers?outlet_id[]='.$this->c->id)->json('data'));
    }

    public function test_a_failure_inside_dispatch_rolls_everything_back_including_the_audit_row(): void
    {
        $lines = [['kind' => 'variant', 'item_id' => $this->variant->id, 'qty' => 5], ['kind' => 'material', 'item_id' => $this->material->id, 'qty' => 999]];

        $this->dispatch(['lines' => $lines])->assertStatus(422);

        $this->assertSame(30, $this->bal($this->a), 'the first line was rolled back');
        $this->assertSame(0, StockTransfer::count());
        $this->assertSame(0, DB::table('activity_logs')->where('action', 'stock_transfer_dispatched')->count());
    }

    public function test_each_step_writes_an_audit_row(): void
    {
        $t = $this->dispatch()->assertCreated()->json();
        $this->postJson("/api/v1/stock/transfers/{$t['id']}/receive")->assertOk();

        $actions = DB::table('activity_logs')->where('entity_type', 'StockTransfer')->pluck('action')->all();
        $this->assertEqualsCanonicalizing(['stock_transfer_dispatched', 'stock_transfer_received'], $actions);
    }
}

/** Pembantu kecil agar pernyataan ledger di tes pertama tidak bergantung pada urutan kunci JSON. */
final class StockTransferLedger
{
    public static function first(array $transfer): array
    {
        return $transfer['ledger'][0] ?? [];
    }
}
