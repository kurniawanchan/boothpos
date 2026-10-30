<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 084 US2 — daftar Selesai di tablet dibatasi shift yang sedang terbuka lewat `GET /orders?session_id=`: pesanan shift lain
 * (mis. yang sudah ditutup) tidak ikut, sedangkan datanya tetap ada di server untuk Penjualan dan laporan.
 */
class ClosedOrdersSessionScopeTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
    }

    private function paidOrder(CashierSession $shift): int
    {
        $tab = $this->apiOpenTab($shift->id)->json('id');
        $this->apiAddLine($tab, $this->makeVariant(stock: 10, price: 10000)->id, 1)->assertCreated();
        $this->apiPay($tab, [['method' => 'cash', 'amount' => 10000]])->assertOk()->assertJsonPath('status', 'completed');

        return $tab;
    }

    public function test_the_list_only_holds_orders_of_the_requested_shift(): void
    {
        $old = $this->makeShift($this->cashier);
        $oldOrder = $this->paidOrder($old);
        $old->update(['status' => 'closed', 'closed_at' => now()]);

        $current = $this->makeShift($this->cashier);
        $currentOrder = $this->paidOrder($current);

        $ids = collect($this->getJson("/api/v1/orders?session_id={$current->id}")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$currentOrder], $ids);
        $this->assertNotContains($oldOrder, $ids);
    }

    public function test_the_closed_shift_orders_stay_in_the_server_data(): void
    {
        $old = $this->makeShift($this->cashier);
        $oldOrder = $this->paidOrder($old);
        $old->update(['status' => 'closed', 'closed_at' => now()]);

        $ids = collect($this->getJson("/api/v1/orders?session_id={$old->id}")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$oldOrder], $ids);
    }

    public function test_without_a_session_filter_the_old_behaviour_lists_both(): void
    {
        $a = $this->makeShift($this->cashier);
        $this->paidOrder($a);
        $a->update(['status' => 'closed', 'closed_at' => now()]);
        $b = $this->makeShift($this->cashier);
        $this->paidOrder($b);

        $this->assertCount(2, $this->getJson('/api/v1/orders')->assertOk()->json('data'));
    }
}
