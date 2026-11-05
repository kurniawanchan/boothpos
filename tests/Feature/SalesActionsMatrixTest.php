<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 057 (US1) — peran dengan Sales = View saja tak bisa mengubah apa pun di Sales; ditegakkan di SERVER, apa pun yang
 * dikirim klien. Tiap rute mutasi/ekspor Sales dipetakan ke tepat satu aksi; bacaan tetap jalan.
 */
class SalesActionsMatrixTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();
        $cashier = User::factory()->create(['role' => 'cashier']);
        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open']);
        $variant = Product::factory()->create(['artist_id' => Artist::factory()->create()->id, 'category_id' => Category::factory()->create()->id])
            ->variants()->create(['sku' => 'SAM0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 500]);
        $this->order = app(OrderService::class)->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(), 'customer_id' => Customer::factory()->create()->id,
            'items' => [['variant_id' => $variant->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 10000]],
        ], $cashier);
        $channel = PaymentChannel::create(['type' => 'qr_ewallet', 'provider' => 'X', 'account_name' => 'T', 'is_active' => true]);
        $this->payment = Payment::create(['order_id' => $this->order->id, 'method' => 'qr_ewallet', 'purpose' => 'settlement', 'amount' => 1000,
            'verification' => 'pending', 'paid_at' => now(), 'channel_id' => $channel->id, 'recorded_by' => $cashier->id]);
    }

    /** @return array<string, array{0:string,1:string,2:string}> [metode, uri, aksi] */
    private function routes(): array
    {
        $o = $this->order->id;
        $p = $this->payment->id;

        return [
            'void' => ['POST', "/api/v1/orders/{$o}/void", 'void'],
            'add payment' => ['POST', "/api/v1/orders/{$o}/payments", 'payment'],
            'delete payment' => ['DELETE', "/api/v1/orders/{$o}/payments/{$p}", 'payment'],
            'confirmation' => ['PATCH', "/api/v1/orders/{$o}/payments/{$p}/confirmation", 'payment'],
            'change method' => ['PATCH', "/api/v1/orders/{$o}/payments/{$p}/method", 'payment_method'],
            'verify one' => ['POST', "/api/v1/orders/{$o}/payments/{$p}/verify", 'verify'],
            'verify bulk' => ['POST', '/api/v1/orders/verify-payments', 'verify'],
            'save reference' => ['PUT', "/api/v1/orders/{$o}/reference", 'reference'],
            'delete reference' => ['DELETE', "/api/v1/orders/{$o}/reference", 'reference'],
            'export' => ['POST', '/api/v1/reports/sales/transactions/export', 'export'],
            'bulk receipts' => ['POST', '/api/v1/orders/bulk-receipts', 'receipts'],
        ];
    }

    private function actAs(array $actions): void
    {
        $u = User::factory()->create();
        $u->update(['role_id' => Role::factory()->create(['menu_keys' => ['sales'], 'menu_actions' => ['sales' => $actions]])->id]);
        $this->actingAs($u->fresh(), 'sanctum');
    }

    public function test_a_view_only_role_is_refused_on_every_sales_mutation_and_nothing_changes(): void
    {
        $this->actAs([]);
        $payments = Payment::count();
        $status = $this->order->status;

        foreach ($this->routes() as $label => [$method, $uri, $action]) {
            $this->json($method, $uri, [])
                ->assertStatus(403)
                ->assertJsonPath('code', 'action_not_allowed')
                ->assertJsonPath('action', $action);
        }

        $this->assertSame($payments, Payment::count());
        $this->assertSame($status, $this->order->fresh()->status);
        $this->assertDatabaseMissing('activity_logs', ['entity_type' => 'Order', 'entity_id' => $this->order->id, 'action' => 'voided']);
    }

    public function test_exactly_the_granted_action_passes_the_gate_and_others_stay_refused(): void
    {
        foreach ($this->routes() as $label => [$method, $uri, $action]) {
            $this->actAs([$action]);

            $response = $this->json($method, $uri, []);

            // Ekspor mengembalikan berkas (bukan JSON): hanya balasan JSON yang bisa membawa `code`.
            $body = json_decode((string) $response->getContent(), true);
            $code = is_array($body) ? ($body['code'] ?? null) : null;
            $this->assertNotSame('action_not_allowed', $code, "{$label}: aksi {$action} seharusnya lolos gerbang");
        }
    }

    public function test_a_role_holding_a_different_action_is_still_refused(): void
    {
        $this->actAs(['export']);

        $this->postJson("/api/v1/orders/{$this->order->id}/void", [])->assertStatus(403)->assertJsonPath('code', 'action_not_allowed');
    }

    public function test_reads_still_work_for_a_view_only_role(): void
    {
        $this->actAs([]);

        $this->getJson("/api/v1/orders/{$this->order->id}")->assertOk();
        $this->getJson('/api/v1/reports/sales')->assertOk();
        $this->getJson("/api/v1/orders/{$this->order->id}/reference")->assertOk();
    }
}
