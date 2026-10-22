<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 065 / US1 + FR-025 — shift aplikasi (membawa X-Device-Id) dan shift web (tanpa header) dipisah: satu pengguna boleh
 * memegang satu shift terbuka dari masing-masing sumber; pembayaran, refund, dan `current` mengikuti SUMBER permintaan.
 */
class ShiftSourceSeparationTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;
    use OverrideFixtures;

    private User $cashier;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $outlet = Outlet::first() ?? Outlet::factory()->create();
        $this->device = Device::forceCreate(['outlet_id' => $outlet->id, 'name' => 'Kasir Depan']);
    }

    private function app(): array
    {
        return ['X-Device-Id' => (string) $this->device->id];
    }

    private function openWeb(float $cash = 100000)
    {
        return $this->postJson('/api/v1/sessions', ['opening_cash' => $cash]);
    }

    private function openApp(float $cash = 50000)
    {
        return $this->postJson('/api/v1/sessions', ['opening_cash' => $cash], $this->app());
    }

    public function test_one_user_can_hold_one_open_shift_per_source(): void
    {
        $web = $this->openWeb()->assertCreated()->json('id');
        $app = $this->openApp()->assertCreated()->json('id');

        $this->assertNotSame($web, $app);
        $this->assertNull(CashierSession::find($web)->device_id);
        $this->assertSame($this->device->id, CashierSession::find($app)->device_id);

        $this->openWeb()->assertStatus(409);
        $this->openApp()->assertStatus(409);
    }

    public function test_current_returns_the_shift_of_the_requests_source_only(): void
    {
        $web = $this->openWeb()->json('id');
        $this->getJson('/api/v1/sessions/current')->assertOk()->assertJsonPath('id', $web);
        $this->getJson('/api/v1/sessions/current', $this->app())->assertStatus(404);

        $app = $this->openApp()->json('id');
        $this->getJson('/api/v1/sessions/current')->assertOk()->assertJsonPath('id', $web);
        $this->getJson('/api/v1/sessions/current', $this->app())->assertOk()->assertJsonPath('id', $app);
    }

    public function test_closing_one_source_leaves_the_other_open(): void
    {
        $web = $this->openWeb()->json('id');
        $app = $this->openApp()->json('id');

        $this->postJson("/api/v1/sessions/{$app}/close", ['closing_cash' => 50000], $this->app() + ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

        $this->assertSame('closed', CashierSession::find($app)->status);
        $this->assertSame('open', CashierSession::find($web)->status);
        $this->getJson('/api/v1/sessions/current')->assertOk()->assertJsonPath('id', $web);
        // Setelah shift aplikasi tertutup, tablet boleh membuka shift baru tanpa menyentuh shift web.
        $this->openApp()->assertCreated();
    }

    public function test_a_later_cash_payment_lands_in_the_shift_of_the_requests_source(): void
    {
        $web = $this->openWeb()->json('id');
        $app = $this->openApp()->json('id');
        $customer = Customer::factory()->create();
        $variant = $this->makeVariant(stock: 10, price: 100000);

        $order = $this->postJson('/api/v1/orders', [
            'session_id' => $web, 'local_ref' => (string) Str::uuid(), 'customer_id' => $customer->id,
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 20000]],
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/orders/{$order}/payments", ['method' => 'cash', 'amount' => 30000, 'client_ref' => (string) Str::uuid()])->assertCreated();
        $this->postJson("/api/v1/orders/{$order}/payments", ['method' => 'cash', 'amount' => 20000, 'client_ref' => (string) Str::uuid()], $this->app())->assertCreated();

        $sessions = Payment::where('order_id', $order)->orderBy('id')->pluck('session_id')->all();
        $this->assertSame([$web, $web, $app], $sessions, 'tunai susulan masuk ke shift sumbernya, bukan shift sumber lain');
    }

    public function test_a_cash_payment_needs_an_open_shift_of_the_requests_source(): void
    {
        $web = $this->openWeb()->json('id');
        $customer = Customer::factory()->create();
        $variant = $this->makeVariant(stock: 10, price: 100000);
        $order = $this->postJson('/api/v1/orders', [
            'session_id' => $web, 'local_ref' => (string) Str::uuid(), 'customer_id' => $customer->id,
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 20000]],
        ])->assertCreated()->json('id');

        // Hanya shift web yang terbuka: tablet (sumber aplikasi) tidak boleh "meminjamnya".
        $this->postJson("/api/v1/orders/{$order}/payments", ['method' => 'cash', 'amount' => 10000, 'client_ref' => (string) Str::uuid()], $this->app())
            ->assertStatus(409);
    }

    public function test_a_refund_is_booked_in_the_shift_of_the_requests_source(): void
    {
        $web = $this->openWeb()->json('id');
        $app = $this->openApp()->json('id');
        $variant = $this->makeVariant(stock: 10, price: 8000);
        $order = $this->postJson('/api/v1/orders', [
            'session_id' => $web, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 8000]],
        ])->assertCreated()->json('id');

        $outlet = (int) CashierSession::find($web)->outlet_id;
        $token = $this->overrideToken('refund', ['order_id' => $order, 'reason_code' => 'wrong_item', 'reason' => 'salah pesan'], $outlet);

        $this->postJson("/api/v1/orders/{$order}/refund", ['reason_code' => 'wrong_item', 'reason' => 'salah pesan', 'override_token' => $token], $this->app())->assertOk();

        $this->assertDatabaseHas('cash_movements', ['session_id' => $app, 'direction' => 'out', 'reason_code' => 'refund']);
        $this->assertDatabaseMissing('cash_movements', ['session_id' => $web, 'reason_code' => 'refund']);
    }

    public function test_a_refund_without_a_shift_of_the_requests_source_is_refused(): void
    {
        $web = $this->openWeb()->json('id');
        $variant = $this->makeVariant(stock: 10, price: 8000);
        $order = $this->postJson('/api/v1/orders', [
            'session_id' => $web, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 8000]],
        ])->assertCreated()->json('id');
        $outlet = (int) CashierSession::find($web)->outlet_id;
        $token = $this->overrideToken('refund', ['order_id' => $order, 'reason_code' => 'wrong_item', 'reason' => 'salah pesan'], $outlet);

        // Tablet tanpa shift aplikasi sendiri: refund tidak boleh masuk ke shift web.
        $this->postJson("/api/v1/orders/{$order}/refund", ['reason_code' => 'wrong_item', 'reason' => 'salah pesan', 'override_token' => $token], $this->app())
            ->assertStatus(409)->assertJsonPath('code', 'shift_required');
    }

    public function test_an_unknown_device_header_cannot_open_a_shift(): void
    {
        $this->postJson('/api/v1/sessions', ['opening_cash' => 1000], ['X-Device-Id' => '999999'])->assertStatus(422)->assertJsonValidationErrors('device_id');
        $this->assertSame(0, CashierSession::count());
    }
}
