<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_open_session_on_active_event(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');
        $event = Event::factory()->create(['status' => 'active']);

        $response = $this->postJson('/api/v1/sessions', [
            'event_id' => $event->id, 'opening_cash' => 500000,
        ]);

        $response->assertCreated()->assertJsonPath('status', 'open');
    }

    public function test_cannot_open_session_on_draft_event(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');
        $event = Event::factory()->create(['status' => 'draft']);

        $this->postJson('/api/v1/sessions', [
            'event_id' => $event->id, 'opening_cash' => 500000,
        ])->assertStatus(409);
    }

    public function test_cannot_open_second_session_while_one_is_open(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');
        $event = Event::factory()->create(['status' => 'active']);

        $this->postJson('/api/v1/sessions', ['event_id' => $event->id, 'opening_cash' => 100000])
            ->assertCreated();

        $this->postJson('/api/v1/sessions', ['event_id' => $event->id, 'opening_cash' => 100000])
            ->assertStatus(409);
    }

    public function test_cashier_cannot_close_another_cashiers_session(): void
    {
        $ownerOfSession = User::factory()->create(['role' => 'cashier']);
        $attacker = User::factory()->create(['role' => 'cashier']);
        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create([
            'event_id' => $event->id, 'user_id' => $ownerOfSession->id, 'status' => 'open',
        ]);

        $this->actingAs($attacker, 'sanctum');

        $response = $this->postJson("/api/v1/sessions/{$session->id}/close", ['closing_cash' => 100000]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('cashier_sessions', ['id' => $session->id, 'status' => 'open']);
    }

    public function test_owner_can_force_close_any_session(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $owner = User::factory()->create(['role' => 'owner']);
        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create([
            'event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open', 'opening_cash' => 100000,
        ]);

        $this->actingAs($owner, 'sanctum');

        $this->postJson("/api/v1/sessions/{$session->id}/close", ['closing_cash' => 100000])
            ->assertOk()->assertJsonPath('status', 'closed');
    }

    public function test_cashier_cannot_view_another_cashiers_session_summary(): void
    {
        // Regresi — celah IDOR ditemukan saat security review: summary()
        // tidak punya pemeriksaan otorisasi sama sekali, padahal close()
        // pada controller yang sama sudah menegakkannya untuk sesi yang
        // sama persis.
        $ownerOfSession = User::factory()->create(['role' => 'cashier']);
        $attacker = User::factory()->create(['role' => 'cashier']);
        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create([
            'event_id' => $event->id, 'user_id' => $ownerOfSession->id, 'status' => 'open',
        ]);

        $this->actingAs($attacker, 'sanctum');

        $this->getJson("/api/v1/sessions/{$session->id}/summary")->assertStatus(403);
    }

    public function test_owner_can_view_any_cashiers_session_summary(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $owner = User::factory()->create(['role' => 'owner']);
        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create([
            'event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open',
        ]);

        $this->actingAs($owner, 'sanctum');

        $this->getJson("/api/v1/sessions/{$session->id}/summary")
            ->assertOk()
            ->assertJsonPath('order_count', 0);
    }

    public function test_cashier_can_view_own_session_summary(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create([
            'event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open',
        ]);

        $this->actingAs($cashier, 'sanctum');

        $this->getJson("/api/v1/sessions/{$session->id}/summary")->assertOk();
    }

    public function test_closing_computes_cash_difference(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');
        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create([
            'event_id' => $event->id, 'user_id' => $user->id, 'status' => 'open', 'opening_cash' => 100000,
        ]);

        // Tanpa order sama sekali, expected_cash = opening_cash saja.
        $response = $this->postJson("/api/v1/sessions/{$session->id}/close", ['closing_cash' => 95000]);

        $response->assertOk();
        $this->assertEquals(100000, $response->json('expected_cash'));
        $this->assertEquals(-5000, $response->json('cash_difference'));
    }
}
