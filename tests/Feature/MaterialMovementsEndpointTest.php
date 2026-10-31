<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\KitchenTicketItem;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 087 US5 (T047) — GET /materials/{material}/movements: buku besar bahan dengan nomor pesanan.
 */
class MaterialMovementsEndpointTest extends TestCase
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

    private function url(array $query = []): string
    {
        return "/api/v1/materials/{$this->mat['telur']->id}/movements".($query ? '?'.http_build_query($query) : '');
    }

    public function test_sales_and_returns_are_listed_newest_first_with_their_order_number(): void
    {
        $id = $this->checkout([[$this->indomie, 2, $this->opts('soto', 'telur')]])->assertCreated()->json('id');
        $subject = ['order_id' => $id, 'reason_code' => 'wrong_item', 'reason' => 'x'];
        $this->postJson("/api/v1/orders/{$id}/refund", $subject + ['override_token' => $this->overrideToken('refund', $subject, $this->outletA->id)])->assertOk();

        $this->actingAs($this->owner, 'sanctum');
        $res = $this->getJson($this->url(['type' => ['sale', 'return']]))->assertOk();

        $this->assertSame(['return', 'sale'], array_column($res->json('data'), 'type'));
        $number = Order::find($id)->order_number;
        $res->assertJsonPath('data.1.reference.order_number', $number)
            ->assertJsonPath('data.1.reference.type', 'order_item')
            ->assertJsonPath('data.1.qty_change', '-2.000')
            ->assertJsonPath('data.1.user.id', $this->cashier->id)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_filters_by_type_outlet_and_date(): void
    {
        $this->checkout([[$this->indomie, 1, $this->opts('soto', 'telur')]])->assertCreated();
        $this->actingAs($this->owner, 'sanctum');

        $this->assertSame(1, $this->getJson($this->url(['type' => ['sale']]))->json('meta.total'));
        // Koreksi awal (fixture) ada di kedua outlet: saring outlet B → hanya koreksinya, tanpa penjualan.
        $this->assertSame(['adjustment'], array_column($this->getJson($this->url(['outlet_id' => [$this->outletB->id]]))->json('data'), 'type'));
        $this->assertSame(0, $this->getJson($this->url(['from' => now()->addDays(2)->toDateString()]))->json('meta.total'));
        $this->getJson($this->url(['type' => ['bogus']]))->assertStatus(422);
    }

    public function test_a_waste_row_for_a_deleted_line_keeps_a_null_order_reference(): void
    {
        $this->indomie->product->forceFill(['station_id' => $this->makeStation('Dapur')->id])->save();
        $tab = $this->apiOpenTab($this->session->id, DiningTable::factory()->create()->id)->json('id');
        $line = max($this->apiAddLine($tab, $this->indomie->id, 1, ['modifier_option_ids' => $this->opts('soto', 'telur')])->json('lines.*.id'));
        $this->postJson("/api/v1/tabs/{$tab}/send")->assertOk();
        KitchenTicketItem::where('order_item_id', $line)->update(['status' => 'preparing']);
        $this->actingAs($this->owner, 'sanctum');
        $this->deleteJson("/api/v1/tabs/{$tab}/items/{$line}", ['reason_code' => 'wrong_item', 'kitchen_status' => 'started', 'stock_decision' => 'waste', 'reason' => 'x'])->assertOk();

        $this->getJson($this->url(['type' => ['waste']]))->assertOk()
            ->assertJsonPath('data.0.reference.id', $line)
            ->assertJsonPath('data.0.reference.order_number', null);
    }

    public function test_rows_of_outlets_outside_the_users_assignment_are_not_visible(): void
    {
        $inventory = User::factory()->create(['role' => 'inventory']);
        $inventory->outlets()->sync([$this->outletB->id]);
        $this->checkout([[$this->indomie, 1, $this->opts('soto', 'telur')]])->assertCreated();

        $this->actingAs($inventory, 'sanctum');
        $types = array_column($this->getJson($this->url())->assertOk()->json('data'), 'outlet_id');

        $this->assertSame([$this->outletB->id], array_values(array_unique($types)));
    }

    public function test_the_query_count_does_not_grow_with_rows(): void
    {
        foreach (range(1, 3) as $_) {
            $this->checkout([[$this->indomie, 1, $this->opts('soto', 'telur')]])->assertCreated();
        }
        $this->actingAs($this->owner, 'sanctum');

        DB::enableQueryLog();
        $this->getJson($this->url(['per_page' => 2]))->assertOk();
        $few = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->getJson($this->url(['per_page' => 25]))->assertOk();
        $many = count(DB::getQueryLog());

        $this->assertSame($few, $many);
    }

    public function test_a_cashier_cannot_read_the_material_ledger(): void
    {
        $this->getJson($this->url())->assertForbidden();
    }
}
