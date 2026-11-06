<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 054 (US12) — riwayat shift kasir: GET /sessions (daftar berhalaman), GET /sessions/{id} (rincian
 * kas awal/penutupan) dan GET /sessions/{id}/transactions. Dikunci di sini: owner/admin melihat semua,
 * kasir hanya miliknya (di daftar DAN rincian), angka shift tertutup = yang DISIMPAN saat ditutup,
 * shift berjalan memakai rumus yang sama dengan close(), dan kas milik shift tempat uang DITERIMA.
 */
class CashierSessionListTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $kasirA;

    private User $kasirB;

    private Event $event;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->kasirA = User::factory()->create(['role' => 'cashier', 'name' => 'Kasir A']);
        $this->kasirB = User::factory()->create(['role' => 'cashier', 'name' => 'Kasir B']);
        $this->event = Event::factory()->create(['status' => 'active', 'name' => 'Sakana Fest']);
        $product = Product::factory()->create(['artist_id' => Artist::factory()->create()->id, 'category_id' => Category::factory()->create()->id]);
        $this->variant = $product->variants()->create(['sku' => 'SES0001', 'sell_price' => 25000, 'cost_price' => 1, 'current_stock' => 1000]);
    }

    private function as(User $user): static
    {
        $this->actingAs($user, 'sanctum');

        return $this;
    }

    private function openSession(User $user, array $over = []): CashierSession
    {
        return CashierSession::factory()->create($over + [
            'event_id' => $this->event->id, 'user_id' => $user->id, 'status' => 'open', 'opening_cash' => 100000, 'opened_at' => now(),
        ]);
    }

    /** Penjualan 50.000 (2 × 25.000) di $session oleh $user, dibayar $tendered tunai. */
    private function sale(CashierSession $session, User $user, float $tendered = 50000, array $extra = []): Order
    {
        return app(OrderService::class)->create(array_merge([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2]], 'payments' => [['method' => 'cash', 'amount' => $tendered]],
        ], $extra), $user);
    }

    // --- daftar --------------------------------------------------------------------

    public function test_the_list_is_newest_first_with_the_pagination_envelope(): void
    {
        foreach ([3, 2, 1] as $daysAgo) {
            $this->openSession($this->kasirA, ['opened_at' => now()->subDays($daysAgo), 'status' => 'closed', 'closed_at' => now()->subDays($daysAgo)->addHours(8)]);
        }

        $res = $this->as($this->owner)->getJson('/api/v1/sessions?per_page=2')->assertOk()->json();

        $this->assertSame(3, $res['meta']['total']);
        $this->assertSame(2, $res['meta']['last_page']);
        $this->assertCount(2, $res['data']);
        $this->assertGreaterThan($res['data'][1]['opened_at'], $res['data'][0]['opened_at']);
        $this->assertEqualsCanonicalizing(['id', 'cashier_id', 'cashier_name', 'event_id', 'event_name', 'opened_at', 'closed_at', 'status', 'opening_cash', 'reopen_count'], array_keys($res['data'][0]));
        $this->assertSame('Kasir A', $res['data'][0]['cashier_name']);
        $this->assertSame('Sakana Fest', $res['data'][0]['event_name']);
        $this->assertSame('100000.00', $res['data'][0]['opening_cash']);
    }

    public function test_the_owner_sees_every_cashier_and_can_filter(): void
    {
        $a = $this->openSession($this->kasirA, ['status' => 'closed', 'closed_at' => now()]);
        $b = $this->openSession($this->kasirB);
        $other = Event::factory()->create(['status' => 'active']);
        $c = $this->openSession($this->kasirB, ['event_id' => $other->id, 'status' => 'closed', 'closed_at' => now()]);
        $this->as($this->owner);

        $ids = fn (string $q) => collect($this->getJson('/api/v1/sessions'.$q)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], $ids(''));
        $this->assertEqualsCanonicalizing([$b->id, $c->id], $ids('?user_id='.$this->kasirB->id));
        $this->assertEqualsCanonicalizing([$c->id], $ids('?event_id='.$other->id));
        $this->assertEqualsCanonicalizing([$a->id, $c->id], $ids('?status=closed'));
        $this->assertSame([], $ids('?date_from=2999-01-01'));
    }

    public function test_a_cashier_only_ever_sees_their_own_sessions_even_when_asking_for_someone_elses(): void
    {
        $mine = $this->openSession($this->kasirA, ['status' => 'closed', 'closed_at' => now()]);
        $theirs = $this->openSession($this->kasirB);
        $this->as($this->kasirA);

        $this->assertSame([$mine->id], collect($this->getJson('/api/v1/sessions')->json('data'))->pluck('id')->all());
        $this->assertSame([$mine->id], collect($this->getJson('/api/v1/sessions?user_id='.$this->kasirB->id)->json('data'))->pluck('id')->all(), 'the user_id filter must not widen a cashier\'s scope');
    }

    public function test_sessions_of_the_other_data_mode_are_not_listed(): void
    {
        $live = $this->openSession($this->kasirA);
        $demo = $this->openSession($this->kasirA);
        DB::table('cashier_sessions')->where('id', $demo->id)->update(['data_mode' => 'demo']);

        $this->assertSame([$live->id], collect($this->as($this->owner)->getJson('/api/v1/sessions')->json('data'))->pluck('id')->all());
    }

    public function test_the_list_does_not_run_a_query_per_row(): void
    {
        $this->openSession($this->kasirA);
        $this->as($this->owner)->getJson('/api/v1/sessions')->assertOk(); // pemanasan
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/sessions');
        $few = count(DB::getQueryLog());

        foreach (range(1, 12) as $i) {
            $this->openSession($this->kasirB);
        }
        DB::flushQueryLog();
        $this->getJson('/api/v1/sessions');

        $this->assertSame($few, count(DB::getQueryLog()));
    }

    public function test_the_static_current_route_still_resolves_next_to_the_new_ones(): void
    {
        $this->openSession($this->kasirA);

        $this->as($this->kasirA)->getJson('/api/v1/sessions/current')->assertOk()->assertJsonPath('status', 'open');
    }

    // --- rincian -------------------------------------------------------------------

    public function test_another_cashiers_session_is_refused_but_the_owner_may_open_it(): void
    {
        $session = $this->openSession($this->kasirB);

        $this->as($this->kasirA)->getJson("/api/v1/sessions/{$session->id}")->assertStatus(403);
        $this->getJson("/api/v1/sessions/{$session->id}/transactions")->assertStatus(403);

        $this->as($this->owner)->getJson("/api/v1/sessions/{$session->id}")->assertOk();
        $this->as($this->kasirB)->getJson("/api/v1/sessions/{$session->id}")->assertOk();
    }

    public function test_a_closed_shift_returns_exactly_what_was_stored_when_it_closed(): void
    {
        $session = $this->openSession($this->kasirA);
        $this->sale($session, $this->kasirA, 60000); // kembalian 10.000
        $this->as($this->kasirA)->postJson("/api/v1/sessions/{$session->id}/close", ['closing_cash' => 145000])->assertOk();
        // Data berubah SETELAH ditutup (penjualan dibatalkan owner): rumus langsung kini memberi angka lain,
        // tetapi rincian tetap mengikuti angka yang DISIMPAN saat ditutup.
        app(OrderService::class)->void(Order::where('session_id', $session->id)->first(), 'salah', $this->owner);
        $stored = $session->fresh();

        $detail = $this->as($this->owner)->getJson("/api/v1/sessions/{$session->id}")->assertOk()->json();

        $this->assertSame('closed', $detail['status']);
        $this->assertSame('100000.00', $detail['opening_cash']);
        $this->assertSame(number_format((float) $stored->expected_cash, 2, '.', ''), $detail['expected_cash']);
        $this->assertSame('145000.00', $detail['closing_cash']);
        $this->assertSame(number_format((float) $stored->cash_difference, 2, '.', ''), $detail['cash_difference']);
        $this->assertSame('150000.00', $detail['expected_cash']); // 100.000 + 60.000 − 10.000
        $this->assertSame('-5000.00', $detail['cash_difference']);
    }

    public function test_an_open_shift_shows_the_expected_cash_so_far_using_the_same_formula_as_closing(): void
    {
        $session = $this->openSession($this->kasirA);
        $this->sale($session, $this->kasirA, 60000);
        $this->sale($session, $this->kasirA);
        $this->as($this->kasirA);

        $detail = $this->getJson("/api/v1/sessions/{$session->id}")->assertOk()->json();
        $this->assertSame('open', $detail['status']);
        $this->assertNull($detail['closing_cash']);
        $this->assertNull($detail['cash_difference']);

        // Menutup shift menyimpan angka dari rumus yang SAMA: harus persis sama dengan yang ditampilkan tadi.
        $this->postJson("/api/v1/sessions/{$session->id}/close", ['closing_cash' => 0])->assertOk();
        $this->assertSame($detail['expected_cash'], number_format((float) $session->fresh()->expected_cash, 2, '.', ''));
        $this->assertSame('200000.00', $detail['expected_cash']); // 100.000 + 60.000 + 50.000 − kembalian 10.000
    }

    public function test_the_detail_carries_sales_totals_methods_and_opening_entries(): void
    {
        $session = $this->openSession($this->kasirA);
        $this->sale($session, $this->kasirA);
        $this->sale($session, $this->kasirA);

        $detail = $this->as($this->kasirA)->getJson("/api/v1/sessions/{$session->id}")->json();

        $this->assertSame(2, $detail['order_count']);
        $this->assertSame('100000.00', $detail['total_sales']);
        $this->assertSame(['cash'], collect($detail['by_method'])->pluck('method')->all());
        $this->assertSame('100000.00', $detail['by_method'][0]['amount']);
        $this->assertSame('Kasir A', $detail['cashier_name']);
        $this->assertIsArray($detail['opening_cash_entries']);
    }

    // --- transaksi -----------------------------------------------------------------

    public function test_transactions_list_the_sales_made_in_the_shift_with_voided_ones_marked(): void
    {
        $session = $this->openSession($this->kasirA);
        $ok = $this->sale($session, $this->kasirA);
        $voided = $this->sale($session, $this->kasirA);
        app(OrderService::class)->void($voided, 'salah', $this->owner);
        $elsewhere = $this->sale($this->openSession($this->kasirB), $this->kasirB);

        $rows = collect($this->as($this->kasirA)->getJson("/api/v1/sessions/{$session->id}/transactions")->assertOk()->json('data'));

        $this->assertEqualsCanonicalizing([$ok->id, $voided->id], $rows->pluck('order_id')->all());
        $this->assertNotContains($elsewhere->id, $rows->pluck('order_id')->all());
        $this->assertSame('voided', $rows->firstWhere('order_id', $voided->id)['status']);
        $this->assertSame('sale', $rows->firstWhere('order_id', $ok->id)['kind']);
        $this->assertSame('50000.00', $rows->firstWhere('order_id', $ok->id)['amount']);
        $this->assertNotNull($rows[0]['order_number']);
    }

    public function test_cash_received_later_belongs_to_the_shift_that_received_it_and_shows_as_payment_received(): void
    {
        $sessionA = $this->openSession($this->kasirA);
        $customer = Customer::factory()->create();
        $order = $this->sale($sessionA, $this->kasirA, 20000, ['customer_id' => $customer->id]); // dibayar sebagian
        $sessionA->update(['status' => 'closed', 'closed_at' => now(), 'closing_cash' => 120000, 'expected_cash' => 120000, 'cash_difference' => 0]);
        $sessionB = $this->openSession($this->kasirB);
        $this->as($this->kasirB)->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'cash', 'amount' => 30000, 'client_ref' => (string) Str::uuid()])->assertCreated();

        $received = collect($this->getJson("/api/v1/sessions/{$sessionB->id}/transactions")->assertOk()->json('data'));
        $row = $received->firstWhere('order_id', $order->id);
        $this->assertSame('payment_received', $row['kind']);
        $this->assertSame('30000.00', $row['amount']);
        $this->assertSame('cash', $row['method']);

        // Shift penjualan aslinya tetap menunjukkan penjualannya, tanpa menerima uang susulan itu.
        $original = collect($this->as($this->owner)->getJson("/api/v1/sessions/{$sessionA->id}/transactions")->assertOk()->json('data'));
        $this->assertSame('sale', $original->firstWhere('order_id', $order->id)['kind']);
        $this->assertNull($original->firstWhere('kind', 'payment_received'));
        // Kas shift B memuat uang itu.
        $this->assertSame('130000.00', $this->getJson("/api/v1/sessions/{$sessionB->id}")->json('expected_cash')); // 100.000 + 30.000
    }

    public function test_transactions_paginate(): void
    {
        $session = $this->openSession($this->kasirA);
        foreach (range(1, 3) as $i) {
            $this->sale($session, $this->kasirA);
        }

        $res = $this->as($this->kasirA)->getJson("/api/v1/sessions/{$session->id}/transactions?per_page=2")->json();

        $this->assertSame(3, $res['meta']['total']);
        $this->assertSame(2, $res['meta']['last_page']);
        $this->assertCount(2, $res['data']);
    }
}
