<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 045 Stage 2 (T031) — outlet sebuah shift dan pewarisannya ke transaksi.
 *
 * Aturan (research.md D7, contracts/api-scope.md):
 *  - POST /sessions memakai outlet yang DIPILIH bila dikirim dan termasuk yang boleh dilihat;
 *    bila tidak dikirim dan hanya ada satu yang berlaku, itulah outletnya; bila ambigu → 422;
 *  - pengguna tanpa penugasan (bukan admin) tidak bisa membuka shift → 409 outlet_unassigned;
 *  - order/pembayaran/tiket mewarisi outlet SHIFT, bukan dari body; body `outlet_id` diabaikan.
 */
class ShiftOutletTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private function openShift(array $body): \Illuminate\Testing\TestResponse
    {
        $event = Event::factory()->create(['status' => 'active']);

        return $this->postJson('/api/v1/sessions', array_merge([
            'event_id' => $event->id,
            'opening_cash' => 0,
        ], $body));
    }

    public function test_the_only_assigned_outlet_becomes_the_shift_outlet_when_none_is_sent(): void
    {
        $outlets = $this->makeOutlets(2);
        $cashier = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$outlets[1]]);
        $this->actingAs($cashier, 'sanctum');

        $this->openShift([])
            ->assertCreated()
            ->assertJsonPath('outlet_id', $outlets[1]->id);
    }

    public function test_several_outlets_without_a_choice_is_refused_with_422(): void
    {
        $outlets = $this->makeOutlets(2);
        $cashier = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$outlets[0], $outlets[1]]);
        $this->actingAs($cashier, 'sanctum');

        $this->openShift([])
            ->assertStatus(422)
            ->assertJsonValidationErrors('outlet_id');

        $this->assertSame(0, CashierSession::count());
    }

    public function test_a_chosen_permitted_outlet_is_used(): void
    {
        $outlets = $this->makeOutlets(2);
        $cashier = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$outlets[0], $outlets[1]]);
        $this->actingAs($cashier, 'sanctum');

        $this->openShift(['outlet_id' => $outlets[1]->id])
            ->assertCreated()
            ->assertJsonPath('outlet_id', $outlets[1]->id);
    }

    public function test_an_outlet_outside_the_permitted_set_is_refused_with_403(): void
    {
        $outlets = $this->makeOutlets(2);
        $cashier = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$outlets[0]]);
        $this->actingAs($cashier, 'sanctum');

        $this->openShift(['outlet_id' => $outlets[1]->id])
            ->assertForbidden()
            ->assertJsonPath('code', 'outlet_forbidden');

        $this->assertSame(0, CashierSession::count());
    }

    public function test_a_user_without_any_assignment_cannot_open_a_shift(): void
    {
        $this->makeOutlets(2);
        $this->actingAs(User::factory()->unassigned()->create(['role' => 'cashier']), 'sanctum');

        $this->openShift([])
            ->assertStatus(409)
            ->assertJsonPath('code', 'outlet_unassigned');
    }

    public function test_an_order_inherits_the_shift_outlet_and_ignores_an_outlet_sent_in_the_body(): void
    {
        $outlets = $this->makeOutlets(2);
        $cashier = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$outlets[0]]);
        $shift = $this->shiftAt($outlets[0], $cashier);
        $product = \App\Models\Product::factory()->create(['home_outlet_id' => $outlets[0]->id, 'category_id' => \App\Models\Category::factory()->create()->id]);
        $variant = $product->variants()->create(['sku' => 'SHF'.fake()->unique()->numerify('######'), 'variant_name' => 'Standard', 'sell_price' => 20000, 'cost_price' => 5000, 'current_stock' => 50]);

        $order = app(\App\Services\OrderService::class)->create([
            'session_id' => $shift->id,
            'local_ref' => (string) \Illuminate\Support\Str::uuid(),
            'outlet_id' => $outlets[1]->id,
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 20000]],
        ], $cashier);

        $this->assertSame($outlets[0]->id, $order->outlet_id);
        $this->assertSame($outlets[0]->id, Order::withoutGlobalScopes()->find($order->id)->outlet_id);
    }
}
