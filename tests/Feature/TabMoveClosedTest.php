<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 084 US2 — mengubah meja pesanan SELESAI (koreksi catatan): hanya penempatan yang berubah, tanpa cek "meja bebas",
 * tetap satu outlet dan meja aktif, tercatat di log aktivitas, dan pesanan batal tetap ditolak.
 */
class TabMoveClosedTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;
    private \App\Models\CashierSession $session;
    private int $tab;
    private DiningTable $from;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->session = $this->makeShift($this->cashier);
        $this->from = DiningTable::factory()->create(['name' => 'A1']);
        $this->tab = $this->apiOpenTab($this->session->id, $this->from->id)->json('id');
        $this->apiAddLine($this->tab, $this->makeVariant(stock: 10, price: 10000)->id, 1)->assertCreated();
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 10000]])->assertOk()->assertJsonPath('status', 'completed');
    }

    public function test_a_completed_order_moves_to_another_table_and_only_the_placement_changes(): void
    {
        $to = DiningTable::factory()->create(['name' => 'B2']);
        $before = Order::find($this->tab);

        $this->postJson("/api/v1/tabs/{$this->tab}/move", ['table_id' => $to->id])
            ->assertOk()
            ->assertJsonPath('table.id', $to->id)
            ->assertJsonPath('status', 'completed');

        $after = Order::find($this->tab);
        $this->assertSame($to->id, $after->table_id);
        $this->assertSame($before->service_type, $after->service_type);
        $this->assertEquals($before->total_amount, $after->total_amount);
        $this->assertEquals($before->paid_amount, $after->paid_amount);
    }

    public function test_the_target_table_may_be_occupied_by_an_open_tab_because_a_closed_order_holds_no_table(): void
    {
        $busy = DiningTable::factory()->create(['name' => 'C3']);
        $this->apiOpenTab($this->session->id, $busy->id)->assertCreated();

        $this->postJson("/api/v1/tabs/{$this->tab}/move", ['table_id' => $busy->id])->assertOk();

        $this->assertSame($busy->id, Order::find($this->tab)->table_id);
    }

    public function test_the_change_is_written_to_the_activity_log(): void
    {
        $to = DiningTable::factory()->create(['name' => 'D4']);

        $this->postJson("/api/v1/tabs/{$this->tab}/move", ['table_id' => $to->id])->assertOk();

        $log = ActivityLog::where('action', 'table_changed')->where('entity_id', $this->tab)->first();
        $this->assertNotNull($log);
        $this->assertSame($this->cashier->id, $log->user_id);
    }

    public function test_an_inactive_table_is_refused_and_nothing_changes(): void
    {
        $off = DiningTable::factory()->inactive()->create();

        $this->postJson("/api/v1/tabs/{$this->tab}/move", ['table_id' => $off->id])->assertStatus(409);

        $this->assertSame($this->from->id, Order::find($this->tab)->table_id);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'table_changed']);
    }

    public function test_a_voided_order_still_cannot_be_moved(): void
    {
        Order::whereKey($this->tab)->update(['status' => Order::STATUS_VOIDED]);

        $this->postJson("/api/v1/tabs/{$this->tab}/move", ['table_id' => DiningTable::factory()->create()->id])->assertStatus(409);
    }

    public function test_a_role_without_floor_access_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'inventory']), 'sanctum');

        $this->postJson("/api/v1/tabs/{$this->tab}/move", ['table_id' => DiningTable::factory()->create()->id])->assertForbidden();
    }
}
