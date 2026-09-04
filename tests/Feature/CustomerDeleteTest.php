<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Order;
use App\Models\Preorder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// 009-ui-ux-refinements (US4, T032) — guarded delete for Customer: hanya
// boleh dihapus bila belum pernah punya order/pre-order (status apa pun),
// mengikuti pola guard hapus Artist/Category. Lihat catatan PII di
// Customer model (L10-14) — response 409 di sini hanya berisi pesan
// generik, tidak menyertakan phone/email/social_handle.
class CustomerDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    public function test_owner_can_delete_customer_without_transactions(): void
    {
        $user = $this->actingAsRole('owner');
        $customer = Customer::factory()->create();

        $this->deleteJson("/api/v1/customers/{$customer->id}")->assertStatus(204);

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('activity_logs', [
            'entity_type' => 'Customer',
            'entity_id' => $customer->id,
            'action' => 'deleted',
            'user_id' => $user->id,
        ]);
    }

    public function test_delete_blocked_when_customer_has_order(): void
    {
        $this->actingAsRole('owner');
        $customer = Customer::factory()->create();
        $event = Event::factory()->create();
        $session = CashierSession::factory()->create(['event_id' => $event->id]);

        Order::create([
            'order_number' => 'ORD-CUST-0001',
            'event_id' => $event->id,
            'session_id' => $session->id,
            'customer_id' => $customer->id,
            'user_id' => $session->user_id,
            'channel' => 'offline',
            'subtotal' => 10000,
            'total_amount' => 10000,
            'paid_amount' => 10000,
        ]);

        $response = $this->deleteJson("/api/v1/customers/{$customer->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'deleted_at' => null]);
        // Guard PII: pesan konflik tidak boleh membocorkan phone/email.
        $response->assertJsonMissingPath('phone')->assertJsonMissingPath('email');
    }

    public function test_delete_blocked_when_customer_has_preorder(): void
    {
        $this->actingAsRole('owner');
        $customer = Customer::factory()->create();
        $user = User::factory()->create(['role' => 'cashier']);

        Preorder::create([
            'preorder_number' => 'PRE-CUST-0001',
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'status' => 'ordered',
            'subtotal' => 10000,
            'total_amount' => 10000,
        ]);

        $this->deleteJson("/api/v1/customers/{$customer->id}")->assertStatus(409);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'deleted_at' => null]);
    }

    public function test_delete_blocked_when_customer_has_cancelled_preorder(): void
    {
        // Edge case any-status: status non-final ('cancelled') tetap
        // memblokir hapus — guard tidak memfilter status sama sekali.
        $this->actingAsRole('owner');
        $customer = Customer::factory()->create();
        $user = User::factory()->create(['role' => 'cashier']);

        Preorder::create([
            'preorder_number' => 'PRE-CUST-0002',
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'status' => 'cancelled',
            'subtotal' => 10000,
            'total_amount' => 10000,
        ]);

        $this->deleteJson("/api/v1/customers/{$customer->id}")->assertStatus(409);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'deleted_at' => null]);
    }

    public function test_cashier_cannot_delete_customer(): void
    {
        $this->actingAsRole('cashier');
        $customer = Customer::factory()->create();

        $this->deleteJson("/api/v1/customers/{$customer->id}")->assertStatus(403);
    }
}
