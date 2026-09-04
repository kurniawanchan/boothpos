<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CashierSession;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Order;
use App\Models\Preorder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// 009-ui-ux-refinements (US4, T031) — guarded delete for Event: hanya
// boleh dihapus bila belum pernah punya order/pre-order (status/soft-
// delete apa pun), mengikuti pola guard hapus Artist/Category.
class EventDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    public function test_owner_can_delete_event_without_transactions(): void
    {
        $user = $this->actingAsRole('owner');
        $event = Event::factory()->create();

        $this->deleteJson("/api/v1/events/{$event->id}")->assertStatus(204);

        $this->assertSoftDeleted('events', ['id' => $event->id]);
        $this->assertDatabaseHas('activity_logs', [
            'entity_type' => 'Event',
            'entity_id' => $event->id,
            'action' => 'deleted',
            'user_id' => $user->id,
        ]);
    }

    public function test_delete_blocked_when_event_has_order(): void
    {
        $this->actingAsRole('owner');
        $event = Event::factory()->create();
        $session = CashierSession::factory()->create(['event_id' => $event->id]);

        Order::create([
            'order_number' => 'ORD-TEST-0001',
            'event_id' => $event->id,
            'session_id' => $session->id,
            'user_id' => $session->user_id,
            'channel' => 'offline',
            'subtotal' => 10000,
            'total_amount' => 10000,
            'paid_amount' => 10000,
        ]);

        $this->deleteJson("/api/v1/events/{$event->id}")->assertStatus(409);
        $this->assertDatabaseHas('events', ['id' => $event->id, 'deleted_at' => null]);
    }

    public function test_delete_blocked_when_event_has_preorder(): void
    {
        $this->actingAsRole('owner');
        $event = Event::factory()->create();
        $customer = Customer::factory()->create();
        $user = User::factory()->create(['role' => 'cashier']);

        Preorder::create([
            'preorder_number' => 'PRE-TEST-0001',
            'event_id' => $event->id,
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'status' => 'ordered',
            'subtotal' => 10000,
            'total_amount' => 10000,
        ]);

        $this->deleteJson("/api/v1/events/{$event->id}")->assertStatus(409);
        $this->assertDatabaseHas('events', ['id' => $event->id, 'deleted_at' => null]);
    }

    public function test_delete_blocked_when_event_has_cancelled_preorder(): void
    {
        // Contoh 'edge case any-status' dari spec: pre-order berstatus
        // non-final (di sini 'cancelled') tetap memblokir hapus event —
        // guard TIDAK memfilter status sama sekali, konsisten dengan
        // Order/Preorder yang tidak memakai SoftDeletes (tidak ada
        // deleted_at untuk difilter withTrashed()), lihat catatan di
        // EventController::destroy().
        $this->actingAsRole('owner');
        $event = Event::factory()->create();
        $customer = Customer::factory()->create();
        $user = User::factory()->create(['role' => 'cashier']);

        Preorder::create([
            'preorder_number' => 'PRE-TEST-0002',
            'event_id' => $event->id,
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'status' => 'cancelled',
            'subtotal' => 10000,
            'total_amount' => 10000,
        ]);

        $this->deleteJson("/api/v1/events/{$event->id}")->assertStatus(409);
        $this->assertDatabaseHas('events', ['id' => $event->id, 'deleted_at' => null]);
    }

    public function test_cashier_cannot_delete_event(): void
    {
        $this->actingAsRole('cashier');
        $event = Event::factory()->create();

        $this->deleteJson("/api/v1/events/{$event->id}")->assertStatus(403);
    }
}
