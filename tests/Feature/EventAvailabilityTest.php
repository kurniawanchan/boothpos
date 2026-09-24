<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 023-event-availability-invoice-redesign (US1, FR-001/FR-002/FR-003).
 */
class EventAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsOwner(): User
    {
        $user = User::factory()->create(['role' => 'owner']);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_available_on_is_accepted_for_a_multi_day_event(): void
    {
        $this->actingAsOwner();

        $response = $this->postJson('/api/v1/events', [
            'name' => 'Comifuro 24', 'start_date' => '2026-11-01', 'end_date' => '2026-11-02',
            'available_on' => 'day_2',
        ]);

        $response->assertCreated()->assertJsonPath('available_on', 'day_2');
    }

    public function test_available_on_is_rejected_for_a_single_day_event(): void
    {
        $this->actingAsOwner();

        $response = $this->postJson('/api/v1/events', [
            'name' => 'One Day Meet', 'start_date' => '2026-11-01', 'end_date' => '2026-11-01',
            'available_on' => 'day_1',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('available_on');
    }

    public function test_event_available_on_date_resolves_day_1_and_day_2_to_the_real_dates(): void
    {
        $event = Event::factory()->create([
            'start_date' => '2026-11-01', 'end_date' => '2026-11-03', 'available_on' => 'day_1',
        ]);
        $this->assertEquals('2026-11-01', $event->availableOnDate()->toDateString());

        $event->available_on = 'day_2';
        $this->assertEquals('2026-11-03', $event->availableOnDate()->toDateString());

        $event->available_on = null;
        $this->assertNull($event->availableOnDate());
    }

    public function test_patch_clears_available_on_when_the_event_collapses_to_a_single_day(): void
    {
        $this->actingAsOwner();
        $event = Event::factory()->create([
            'start_date' => '2026-11-01', 'end_date' => '2026-11-02', 'available_on' => 'day_1',
        ]);

        $response = $this->patchJson("/api/v1/events/{$event->id}", [
            'name' => $event->name, 'location' => $event->location,
            'start_date' => '2026-11-01', 'end_date' => '2026-11-01',
        ]);

        $response->assertOk()->assertJsonPath('available_on', null);
        $this->assertNull($event->fresh()->available_on);
    }

    public function test_patch_leaves_available_on_untouched_when_the_event_stays_multi_day(): void
    {
        $this->actingAsOwner();
        $event = Event::factory()->create([
            'start_date' => '2026-11-01', 'end_date' => '2026-11-03', 'available_on' => 'day_2',
        ]);

        $response = $this->patchJson("/api/v1/events/{$event->id}", [
            'name' => $event->name, 'location' => $event->location,
            'start_date' => '2026-11-01', 'end_date' => '2026-11-04', 'available_on' => 'day_2',
        ]);

        $response->assertOk()->assertJsonPath('available_on', 'day_2');
    }
}
