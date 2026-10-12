<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_open_a_session_without_any_event(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');

        $response = $this->postJson('/api/v1/sessions', ['opening_cash' => 500000]);

        // 046 — tanpa event sama sekali: shift hanya butuh kas awal (dan outlet, dipilih server).
        $response->assertCreated()->assertJsonPath('status', 'open');
        $this->assertNull(CashierSession::first()->event_id);
    }

    public function test_an_event_id_in_the_request_is_ignored(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/v1/sessions', ['event_id' => 999, 'opening_cash' => 100000])->assertCreated();

        $this->assertNull(CashierSession::first()->event_id);
    }

    public function test_cannot_open_second_session_while_one_is_open(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/v1/sessions', ['opening_cash' => 100000])->assertCreated();

        $this->postJson('/api/v1/sessions', ['opening_cash' => 100000])->assertStatus(409);
    }

    public function test_cashier_cannot_close_another_cashiers_session(): void
    {
        $ownerOfSession = User::factory()->create(['role' => 'cashier']);
        $attacker = User::factory()->create(['role' => 'cashier']);
        $session = CashierSession::factory()->create([
            'user_id' => $ownerOfSession->id, 'status' => 'open',
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
        $session = CashierSession::factory()->create([
            'user_id' => $cashier->id, 'status' => 'open', 'opening_cash' => 100000,
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
        $session = CashierSession::factory()->create([
            'user_id' => $ownerOfSession->id, 'status' => 'open',
        ]);

        $this->actingAs($attacker, 'sanctum');

        $this->getJson("/api/v1/sessions/{$session->id}/summary")->assertStatus(403);
    }

    public function test_owner_can_view_any_cashiers_session_summary(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $owner = User::factory()->create(['role' => 'owner']);
        $session = CashierSession::factory()->create([
            'user_id' => $cashier->id, 'status' => 'open',
        ]);

        $this->actingAs($owner, 'sanctum');

        $this->getJson("/api/v1/sessions/{$session->id}/summary")
            ->assertOk()
            ->assertJsonPath('order_count', 0);
    }

    public function test_cashier_can_view_own_session_summary(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $session = CashierSession::factory()->create([
            'user_id' => $cashier->id, 'status' => 'open',
        ]);

        $this->actingAs($cashier, 'sanctum');

        $this->getJson("/api/v1/sessions/{$session->id}/summary")->assertOk();
    }

    public function test_closing_computes_cash_difference(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');
        $session = CashierSession::factory()->create([
            'user_id' => $user->id, 'status' => 'open', 'opening_cash' => 100000,
        ]);

        // Tanpa order sama sekali, expected_cash = opening_cash saja.
        $response = $this->postJson("/api/v1/sessions/{$session->id}/close", ['closing_cash' => 95000]);

        $response->assertOk();
        $this->assertEquals(100000, $response->json('expected_cash'));
        $this->assertEquals(-5000, $response->json('cash_difference'));
    }
}
