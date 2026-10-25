<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Product;
use App\Models\Station;
use App\Models\User;
use App\Services\OutletMoveGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 071 — OutletMoveGuard: satu-satunya tempat aturan "meja/stasiun boleh dipindah outlet". Meja: tidak ada tab terbuka.
 * Stasiun: tidak dipakai kategori/item menu dan tidak ada tiket dapur yang belum selesai.
 */
class OutletMoveGuardTest extends TestCase
{
    use OutletFixtures, RefreshDatabase, RestaurantFixtures;

    private function guard(): OutletMoveGuard
    {
        return app(OutletMoveGuard::class);
    }

    private function orderOnTable(DiningTable $table, string $status): Order
    {
        $cashier = User::factory()->create(['role' => 'cashier']);

        return $this->makeOpenOrder($this->makeShift($cashier), $cashier, ['table_id' => $table->id, 'status' => $status]);
    }

    public function test_a_table_with_an_open_tab_is_blocked(): void
    {
        $table = DiningTable::factory()->create();
        $this->orderOnTable($table, Order::STATUS_OPEN);

        $this->assertSame(OutletMoveGuard::OPEN_TAB, $this->guard()->tableBlocker($table));
    }

    public function test_a_table_with_only_finished_orders_or_none_may_move(): void
    {
        $free = DiningTable::factory()->create();
        $used = DiningTable::factory()->create();
        $this->orderOnTable($used, Order::STATUS_COMPLETED);
        $this->orderOnTable($used, Order::STATUS_VOIDED);

        $this->assertNull($this->guard()->tableBlocker($free));
        $this->assertNull($this->guard()->tableBlocker($used));
    }

    public function test_a_station_used_by_a_category_or_a_product_is_blocked(): void
    {
        $byCategory = $this->makeStation('Dapur');
        $byProduct = $this->makeStation('Bar');
        Category::factory()->create(['station_id' => $byCategory->id]);
        Product::factory()->create(['station_id' => $byProduct->id, 'category_id' => Category::factory()->create()->id]);

        $this->assertSame(OutletMoveGuard::IN_USE, $this->guard()->stationBlocker($byCategory));
        $this->assertSame(OutletMoveGuard::IN_USE, $this->guard()->stationBlocker($byProduct));
    }

    public function test_a_soft_deleted_product_still_blocks_the_station(): void
    {
        $station = $this->makeStation('Dapur');
        $product = Product::factory()->create(['station_id' => $station->id, 'category_id' => Category::factory()->create()->id]);
        $product->delete();

        $this->assertSame(OutletMoveGuard::IN_USE, $this->guard()->stationBlocker($station));
    }

    public function test_a_station_with_unfinished_ticket_items_is_blocked_whatever_the_status(): void
    {
        foreach (['new', 'preparing', 'ready'] as $status) {
            $station = $this->makeStation("S-{$status}");
            $this->makeTicketItem(['status' => $status], $station);

            $this->assertSame(OutletMoveGuard::OPEN_TICKETS, $this->guard()->stationBlocker($station), $status);
        }
    }

    public function test_a_station_whose_tickets_are_all_served_or_cancelled_may_move(): void
    {
        $station = $this->makeStation('Dapur');
        $this->makeTicketItem(['status' => 'served'], $station);
        $this->makeTicketItem(['status' => 'cancelled'], $station);

        $this->assertNull($this->guard()->stationBlocker($station));
    }

    public function test_an_unused_station_may_move(): void
    {
        $this->assertNull($this->guard()->stationBlocker($this->makeStation('Kosong')));
    }

    public function test_the_guard_sees_other_outlets_and_modes_because_foreign_keys_do(): void
    {
        [$a, $b] = $this->makeOutlets(2)->all();
        $station = new Station(['name' => 'Dapur']);
        $station->outlet_id = $a->id;
        $station->save();
        // Kategori milik outlet lain pun menunjuk stasiun ini: tetap menahan pemindahan.
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        app(\App\Support\OutletContext::class)->bind(User::first(), $b->id);
        Category::factory()->create(['station_id' => $station->id]);

        $this->assertSame(OutletMoveGuard::IN_USE, $this->guard()->stationBlocker($station));
    }
}
