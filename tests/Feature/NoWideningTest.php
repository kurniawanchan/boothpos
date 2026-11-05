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
 * 057 (US5, FR-009) — izin aksi hanya MEMPERSEMPIT: peran dengan SEMUA aksi pada Sales tetap ditolak di aturan yang
 * hari ini khusus owner/admin atau pencatat pembayaran, dan peran owner-setara tetap ditolak bila aksinya dimatikan.
 */
class NoWideningTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    private Payment $payment;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $this->cashier->id, 'status' => 'open']);
        $variant = Product::factory()->create(['artist_id' => Artist::factory()->create()->id, 'category_id' => Category::factory()->create()->id])
            ->variants()->create(['sku' => 'NW0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 500]);
        $this->order = app(OrderService::class)->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(), 'customer_id' => Customer::factory()->create()->id,
            'items' => [['variant_id' => $variant->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 10000]],
        ], $this->cashier);
        $channel = PaymentChannel::create(['type' => 'qr_ewallet', 'provider' => 'X', 'account_name' => 'T', 'is_active' => true]);
        $this->payment = Payment::create(['order_id' => $this->order->id, 'method' => 'qr_ewallet', 'purpose' => 'settlement', 'amount' => 1000,
            'verification' => 'pending', 'paid_at' => now(), 'channel_id' => $channel->id, 'recorded_by' => $this->cashier->id]);
    }

    private function actAsRole(array $menus, ?array $actions): User
    {
        $u = User::factory()->create();
        $u->update(['role_id' => Role::factory()->create(['menu_keys' => $menus, 'menu_actions' => $actions])->id]);
        $this->actingAs($u->fresh(), 'sanctum');

        return $u->fresh();
    }

    private function code($response): ?string
    {
        return $response->json('code');
    }

    public function test_every_sales_action_does_not_make_a_non_privileged_role_owner_equivalent(): void
    {
        $this->actAsRole(['sales'], null);   // semua aksi Sales, tetapi TANPA menu settings (bukan owner/admin)

        // void: hari ini hanya peran dengan menu settings → tetap 403, dan BUKAN karena aksi (gerbang aksi lolos)
        $void = $this->postJson("/api/v1/orders/{$this->order->id}/void", ['reason' => 'x']);
        $void->assertStatus(403);
        $this->assertNotSame('action_not_allowed', $this->code($void));

        // hapus pembayaran: khusus owner/admin
        $del = $this->deleteJson("/api/v1/orders/{$this->order->id}/payments/{$this->payment->id}");
        $this->assertContains($del->status(), [403, 409]);
        $this->assertNotSame('action_not_allowed', $this->code($del));
        $this->assertDatabaseHas('payments', ['id' => $this->payment->id]);
    }

    public function test_cost_and_margin_stay_hidden_without_the_reports_menu_whatever_the_sales_actions(): void
    {
        $this->actAsRole(['sales'], null);

        $row = $this->getJson('/api/v1/reports/sales')->assertOk()->json('transactions.0');

        $this->assertArrayNotHasKey('cost_total', $row);
        $this->assertArrayNotHasKey('margin_amount', $row);
    }

    public function test_the_recorder_rule_still_blocks_verifying_ones_own_payment_even_with_the_verify_action(): void
    {
        $recorder = $this->cashier;
        $recorder->update(['role_id' => Role::factory()->create(['menu_keys' => ['sales'], 'menu_actions' => null])->id]);
        $this->actingAs($recorder->fresh(), 'sanctum');

        $r = $this->postJson("/api/v1/orders/{$this->order->id}/payments/{$this->payment->id}/verify");

        $this->assertNotSame('action_not_allowed', $this->code($r));
        $this->assertSame('pending', $this->payment->fresh()->verification);   // pemisahan tugas tetap berlaku
    }

    public function test_an_owner_equivalent_role_is_refused_when_the_action_is_off(): void
    {
        // peran owner-setara (punya settings) tetapi aksi void dimatikan: gerbang aksi MEMPERSEMPIT
        $this->actAsRole(['sales', 'settings'], ['sales' => ['verify'], 'settings' => ['edit']]);

        $this->postJson("/api/v1/orders/{$this->order->id}/void", ['reason' => 'x'])
            ->assertStatus(403)->assertJsonPath('code', 'action_not_allowed')->assertJsonPath('action', 'void');
        $this->assertSame('completed', $this->order->fresh()->status);
    }

    public function test_the_same_owner_equivalent_role_can_void_when_the_action_is_on(): void
    {
        $this->actAsRole(['sales', 'settings'], ['sales' => ['void'], 'settings' => ['edit']]);

        $this->postJson("/api/v1/orders/{$this->order->id}/void", ['reason' => 'salah input'])->assertOk();
        $this->assertSame('voided', $this->order->fresh()->status);
    }
}
