<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    public function test_owner_can_create_event(): void
    {
        $this->actingAsRole('owner');

        $response = $this->postJson('/api/v1/events', [
            'name' => 'Comifuro 20', 'start_date' => '2026-10-24', 'end_date' => '2026-10-25',
        ]);

        $response->assertCreated()->assertJsonPath('status', 'draft');
    }

    public function test_inventory_role_cannot_create_event(): void
    {
        $this->actingAsRole('inventory');

        $this->postJson('/api/v1/events', [
            'name' => 'Test', 'start_date' => '2026-10-24', 'end_date' => '2026-10-25',
        ])->assertStatus(403);
    }

    public function test_draft_can_transition_to_active(): void
    {
        $this->actingAsRole('owner');
        $event = Event::factory()->create(['status' => 'draft']);

        $this->patchJson("/api/v1/events/{$event->id}/status", ['status' => 'active'])
            ->assertOk()->assertJsonPath('status', 'active');
    }

    public function test_draft_cannot_transition_directly_to_closed(): void
    {
        $this->actingAsRole('owner');
        $event = Event::factory()->create(['status' => 'draft']);

        $this->patchJson("/api/v1/events/{$event->id}/status", ['status' => 'closed'])
            ->assertStatus(409);
    }

    public function test_active_event_cannot_close_with_open_session(): void
    {
        $this->actingAsRole('owner');
        $event = Event::factory()->create(['status' => 'active']);
        CashierSession::factory()->create(['event_id' => $event->id, 'status' => 'open']);

        $this->patchJson("/api/v1/events/{$event->id}/status", ['status' => 'closed'])
            ->assertStatus(409);
    }

    public function test_active_event_closes_when_no_open_sessions(): void
    {
        $this->actingAsRole('owner');
        $event = Event::factory()->create(['status' => 'active']);

        $this->patchJson("/api/v1/events/{$event->id}/status", ['status' => 'closed'])
            ->assertOk()->assertJsonPath('status', 'closed');
    }
}
