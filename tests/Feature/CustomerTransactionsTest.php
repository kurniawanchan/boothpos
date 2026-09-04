<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Order;
use App\Models\Preorder;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 009-ui-ux-refinements User Story 5 (T041) — GET /customers/{customer}/transactions.
 */
class CustomerTransactionsTest extends TestCase
{
    use RefreshDatabase;

    private User $actingUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingUser = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->actingUser, 'sanctum');
    }

    public function test_returns_orders_and_preorders_sorted_by_date_descending(): void
    {
        $customer = Customer::factory()->create();
        $event = Event::factory()->create();
        $session = CashierSession::factory()->create(['event_id' => $event->id]);

        $order = Order::create([
            'order_number' => 'ORD-0001',
            'event_id' => $event->id,
            'session_id' => $session->id,
            'customer_id' => $customer->id,
            'user_id' => $this->actingUser->id,
            'channel' => 'offline',
            'subtotal' => 100000,
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'change_amount' => 0,
            'status' => 'completed',
        ]);
        $order->created_at = now()->subDay();
        $order->save();

        $preorder = Preorder::create([
            'preorder_number' => 'PRE-0001',
            'event_id' => $event->id,
            'customer_id' => $customer->id,
            'user_id' => $session->user_id,
            'status' => 'ordered',
            'fulfillment' => 'pickup',
            'subtotal' => 50000,
            'total_amount' => 50000,
            'paid_amount' => 0,
        ]);
        $preorder->created_at = now();
        $preorder->save();

        $response = $this->getJson("/api/v1/customers/{$customer->id}/transactions");

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(2, $data);
        // Terbaru (preorder) di urutan pertama.
        $this->assertSame('preorder', $data[0]['type']);
        $this->assertSame('PRE-0001', $data[0]['number']);
        $this->assertSame('ordered', $data[0]['status']);
        $this->assertSame('50000.00', $data[0]['total_amount']);

        $this->assertSame('order', $data[1]['type']);
        $this->assertSame('ORD-0001', $data[1]['number']);
        $this->assertSame('completed', $data[1]['status']);
        $this->assertSame('100000.00', $data[1]['total_amount']);

        // Payload hanya berisi field transaksi, tidak membocorkan PII pelanggan.
        $this->assertEqualsCanonicalizing(
            ['type', 'id', 'number', 'status', 'total_amount', 'date'],
            array_keys($data[0])
        );
        $responseBody = $response->getContent();
        $this->assertStringNotContainsString($customer->phone ?? '__none__', $responseBody);
        $this->assertStringNotContainsString($customer->email ?? '__none__', $responseBody);
    }


    public function test_returns_empty_array_for_customer_with_no_transactions(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->getJson("/api/v1/customers/{$customer->id}/transactions");

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_returns_404_for_nonexistent_customer(): void
    {
        $response = $this->getJson('/api/v1/customers/999999/transactions');

        $response->assertStatus(404);
    }

    public function test_returns_404_for_customer_in_other_data_mode(): void
    {
        $customer = ModeGate::runAs('demo', fn () => Customer::factory()->create());

        // Mode aktif default adalah 'live' — pelanggan demo tidak boleh
        // ditemukan lewat route-model-binding karena DataModeScope global.
        $response = $this->getJson("/api/v1/customers/{$customer->id}/transactions");

        $response->assertStatus(404);
    }
}
