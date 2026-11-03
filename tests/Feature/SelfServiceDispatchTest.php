<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SelfServiceOrder;
use App\Models\Setting;
use App\Models\Station;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\KitchenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\Support\SelfServiceFixtures;
use Tests\TestCase;

/**
 * 090 / US6 — "Kirim ke dapur": kiriman tertahan menjadi order nyata lewat layanan yang sudah ada, atau gagal TANPA sisa.
 */
class SelfServiceDispatchTest extends TestCase
{
    use OutletFixtures;
    use RefreshDatabase;
    use SelfServiceFixtures;

    private User $owner;

    private Station $kitchen;

    private $coffee;

    private $rice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ssSetUp();
        $this->owner = $this->ssOwner();
        $this->kitchen = $this->makeStation('Dapur');
        $this->coffee = $this->makeMenuItem(categoryStation: $this->kitchen, stock: 10, price: 20000);
        $this->rice = $this->makeMenuItem(categoryStation: $this->kitchen, stock: 10, price: 15000);
    }

    /** Kiriman terverifikasi, siap dikirim. */
    private function verified(?array $items = null, array $extra = []): SelfServiceOrder
    {
        $placed = $this->ssPlaceOrder($items ?? [['variant_id' => $this->coffee->id, 'qty' => 2], ['variant_id' => $this->rice->id, 'qty' => 1]], $extra);
        $this->actingAs($this->owner, 'sanctum');
        $this->postJson("/api/v1/self-service/orders/{$placed->id}/verify", ['reference' => 'BCA-1234'])->assertOk();

        return $placed->fresh();
    }

    private function dispatchIt(SelfServiceOrder $o)
    {
        return $this->postJson("/api/v1/self-service/orders/{$o->id}/dispatch");
    }

    private function assertNothingLeftBehind(): void
    {
        $this->assertSame(0, Order::count());
        $this->assertSame(0, KitchenTicket::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, StockMovement::where('type', 'sale')->count());
        $this->assertSame(10, (int) $this->coffee->fresh()->current_stock);
        $this->assertSame(10, (int) $this->rice->fresh()->current_stock);
    }

    public function test_dispatching_builds_the_order_ticket_stock_and_verified_payment_through_the_existing_services(): void
    {
        $shift = $this->makeShift($this->owner);
        $submission = $this->verified();

        $res = $this->dispatchIt($submission)->assertOk()
            ->assertJsonPath('data.status', 'dispatched')
            ->assertJsonPath('tickets', 1);

        $order = Order::findOrFail($res->json('order.id'));
        $this->assertSame(Order::STATUS_COMPLETED, $order->status);
        $this->assertSame(Order::SOURCE_SELF_SERVICE, $order->source);
        $this->assertSame($submission->id, (int) $order->self_service_order_id);
        $this->assertSame($this->ssTable->id, (int) $order->table_id);
        $this->assertSame('dine_in', $order->service_type);
        $this->assertSame($shift->id, (int) $order->session_id);
        $this->assertNull($order->device_id);
        $this->assertEqualsWithDelta(55000, (float) $order->total_amount, 0.001);
        $this->assertSame($res->json('order.order_number'), $order->order_number);

        // Pembayaran: satu QRIS terverifikasi, bukti tertaut, referensi dan pemverifikasi tercatat.
        $payment = Payment::where('order_id', $order->id)->sole();
        $this->assertSame('qr_ewallet', $payment->method);
        $this->assertSame('verified', $payment->verification);
        $this->assertEqualsWithDelta(55000, (float) $payment->amount, 0.001);
        $this->assertSame('BCA-1234', $payment->reference);
        $this->assertSame($this->owner->id, (int) $payment->verified_by);
        $this->assertSame($this->ssChannel->id, (int) $payment->channel_id);
        $this->assertSame($payment->id, (int) $submission->proof->fresh()->payment_id);

        // Stok bergerak lewat jalur `sale` yang sama dengan tab biasa.
        $this->assertSame(8, (int) $this->coffee->fresh()->current_stock);
        $this->assertSame(9, (int) $this->rice->fresh()->current_stock);

        // Tiket dapur membawa meja dan Dine-In.
        $ticket = KitchenTicket::where('order_id', $order->id)->sole();
        $this->assertSame($this->kitchen->id, (int) $ticket->station_id);
        $feed = app(KitchenService::class)->feed($this->kitchen);
        $this->assertSame('Meja 5', $feed->first()->order->table->name);

        $row = $submission->fresh();
        $this->assertSame(SelfServiceOrder::DISPATCHED, $row->status);
        $this->assertSame($order->id, (int) $row->order_id);
        $this->assertSame($this->owner->id, (int) $row->dispatched_by);
        $this->assertNotNull(ActivityLog::where('action', 'self_service_dispatched')->first());
    }

    public function test_the_take_away_choice_and_tax_are_carried_through(): void
    {
        Setting::updateOrCreate(['key' => 'tax_rate'], ['value' => '10', 'type' => 'string']);
        $this->makeShift($this->owner);
        $submission = $this->verified([['variant_id' => $this->coffee->id, 'qty' => 1]], ['service_type' => 'takeaway']);

        $order = Order::findOrFail($this->dispatchIt($submission)->assertOk()->json('order.id'));

        $this->assertSame('takeaway', $order->service_type);
        $this->assertEqualsWithDelta(2000, (float) $order->tax_amount, 0.001);
        $this->assertEqualsWithDelta(22000, (float) $order->total_amount, 0.001);
        $this->assertEqualsWithDelta(22000, (float) Payment::where('order_id', $order->id)->sole()->amount, 0.001);
    }

    public function test_it_is_refused_until_the_payment_is_verified(): void
    {
        $this->makeShift($this->owner);
        $placed = $this->ssPlaceOrder([['variant_id' => $this->coffee->id, 'qty' => 1]]);
        $this->actingAs($this->owner, 'sanctum');

        $this->dispatchIt($placed)->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
        $this->assertNothingLeftBehind();
    }

    public function test_it_needs_an_open_shift_at_the_orders_outlet_and_leaves_nothing_behind(): void
    {
        $submission = $this->verified();

        $this->dispatchIt($submission)->assertStatus(409)->assertJsonPath('code', 'no_open_shift');
        $this->assertNothingLeftBehind();
        $this->assertSame(SelfServiceOrder::VERIFIED, $submission->fresh()->status);

        // Shift di outlet LAIN tidak cukup.
        [, $other] = $this->makeOutlets(2);
        $this->shiftAt($other, $this->owner);
        $this->dispatchIt($submission)->assertStatus(409)->assertJsonPath('code', 'no_open_shift');
        $this->assertNothingLeftBehind();
    }

    public function test_a_price_change_after_ordering_is_refused_and_rolls_everything_back(): void
    {
        $this->makeShift($this->owner);
        $submission = $this->verified([['variant_id' => $this->coffee->id, 'qty' => 2]]);
        $this->coffee->update(['sell_price' => 25000]);

        $this->dispatchIt($submission)->assertStatus(409)->assertJsonPath('code', 'total_changed')
            ->assertJsonPath('paid', '40000.00')->assertJsonPath('current', '50000.00');

        $this->assertNothingLeftBehind();
        $this->assertSame(SelfServiceOrder::VERIFIED, $submission->fresh()->status, 'tetap bisa ditolak');
    }

    public function test_a_tax_change_after_ordering_is_refused_too(): void
    {
        $this->makeShift($this->owner);
        $submission = $this->verified([['variant_id' => $this->coffee->id, 'qty' => 1]]);
        Setting::updateOrCreate(['key' => 'tax_rate'], ['value' => '10', 'type' => 'string']);

        $this->dispatchIt($submission)->assertStatus(409)->assertJsonPath('code', 'total_changed');
        $this->assertNothingLeftBehind();
    }

    public function test_an_item_that_became_unavailable_is_reported_and_nothing_is_created(): void
    {
        $this->makeShift($this->owner);
        $submission = $this->verified();
        $this->ssMarkSoldOut($this->rice->product);

        $res = $this->dispatchIt($submission)->assertStatus(409)->assertJsonPath('code', 'item_unavailable');

        $this->assertSame([$this->rice->id], collect($res->json('items'))->pluck('variant_id')->all());
        $this->assertSame('sold_out', $res->json('items.0.reason'));
        $this->assertNothingLeftBehind();
    }

    public function test_a_table_with_an_open_cashier_tab_is_neither_blocked_nor_changed(): void
    {
        $this->makeShift($this->owner);
        $other = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($other, 'sanctum');
        $cashierTab = $this->postJson('/api/v1/tabs', ['session_id' => $this->makeShift($other)->id, 'table_id' => $this->ssTable->id])->assertCreated()->json('id');
        $submission = $this->verified();

        $this->dispatchIt($submission)->assertOk();

        $this->assertSame($this->ssTable->id, (int) Order::where('self_service_order_id', $submission->id)->value('table_id'));
        $tab = Order::findOrFail($cashierTab);
        $this->assertSame(Order::STATUS_OPEN, $tab->status);
        $this->assertSame(1, Order::where('table_id', $this->ssTable->id)->where('status', Order::STATUS_OPEN)->count());
    }

    public function test_dispatching_twice_creates_only_one_order(): void
    {
        $this->makeShift($this->owner);
        $submission = $this->verified();

        $this->dispatchIt($submission)->assertOk();
        $this->dispatchIt($submission)->assertStatus(409)->assertJsonPath('code', 'invalid_transition');

        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, KitchenTicket::count());
        $this->assertSame(8, (int) $this->coffee->fresh()->current_stock);
    }

    public function test_any_staff_with_the_menu_and_an_open_shift_can_send_after_the_owner_verified(): void
    {
        $submission = $this->verified();
        $cashier = $this->ssCashier();
        $this->makeShift($cashier);
        $this->actingAs($cashier, 'sanctum');

        $this->dispatchIt($submission)->assertOk();

        $this->assertSame($cashier->id, (int) $submission->fresh()->dispatched_by);
        $this->assertSame($cashier->id, (int) Order::sole()->user_id);
    }

    public function test_a_role_without_the_menu_cannot_dispatch(): void
    {
        $this->makeShift($this->owner);
        $submission = $this->verified();
        $this->actingAs(User::factory()->create(['role' => 'inventory']), 'sanctum');

        $this->dispatchIt($submission)->assertForbidden();
        $this->assertNothingLeftBehind();
    }

    public function test_the_dispatched_order_is_a_normal_sale_in_the_reports_flagged_as_self_service(): void
    {
        $this->makeShift($this->owner);
        $submission = $this->verified([['variant_id' => $this->coffee->id, 'qty' => 1]]);
        $this->dispatchIt($submission)->assertOk();

        $order = Order::sole();
        $this->getJson('/api/v1/orders/'.$order->id)->assertOk()
            ->assertJsonPath('source', 'self_service')->assertJsonPath('service_type', 'dine_in');
    }
}
