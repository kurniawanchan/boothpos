<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionOpeningCashPerArtistTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCashier(): User
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_opening_a_session_with_entries_summing_to_opening_cash_succeeds(): void
    {
        $this->actingAsCashier();
        $event = Event::factory()->create(['status' => 'active']);
        $artistA = Artist::factory()->create();
        $artistB = Artist::factory()->create();

        $response = $this->postJson('/api/v1/sessions', [
            'event_id' => $event->id,
            'opening_cash' => 80000,
            'opening_cash_entries' => [
                ['artist_id' => $artistA->id, 'amount' => 50000],
                ['artist_id' => $artistB->id, 'amount' => 30000],
            ],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('session_opening_cash_entries', ['artist_id' => $artistA->id, 'amount' => 50000]);
        $this->assertDatabaseHas('session_opening_cash_entries', ['artist_id' => $artistB->id, 'amount' => 30000]);
    }

    public function test_a_mismatched_sum_is_rejected_and_no_session_is_created(): void
    {
        $this->actingAsCashier();
        $event = Event::factory()->create(['status' => 'active']);
        $artist = Artist::factory()->create();

        $this->postJson('/api/v1/sessions', [
            'event_id' => $event->id,
            'opening_cash' => 100000,
            'opening_cash_entries' => [['artist_id' => $artist->id, 'amount' => 40000]],
        ])->assertStatus(422);

        $this->assertDatabaseCount('cashier_sessions', 0);
    }

    public function test_a_session_opened_the_old_way_without_entries_still_works(): void
    {
        $this->actingAsCashier();
        $event = Event::factory()->create(['status' => 'active']);

        $response = $this->postJson('/api/v1/sessions', ['event_id' => $event->id, 'opening_cash' => 100000]);

        $response->assertCreated()->assertJsonPath('opening_cash', '100000.00');
        $sessionId = $response->json('id');

        $summary = $this->getJson("/api/v1/sessions/{$sessionId}/summary");
        $summary->assertOk()->assertJsonPath('opening_cash_entries', []);
    }

    public function test_summary_includes_the_per_artist_breakdown(): void
    {
        $this->actingAsCashier();
        $event = Event::factory()->create(['status' => 'active']);
        $artist = Artist::factory()->create(['name' => 'Nekoyama Studio']);

        $response = $this->postJson('/api/v1/sessions', [
            'event_id' => $event->id,
            'opening_cash' => 50000,
            'opening_cash_entries' => [['artist_id' => $artist->id, 'amount' => 50000]],
        ]);
        $sessionId = $response->json('id');

        $summary = $this->getJson("/api/v1/sessions/{$sessionId}/summary");
        $summary->assertOk()
            ->assertJsonPath('opening_cash_entries.0.artist_name', 'Nekoyama Studio')
            ->assertJsonPath('opening_cash_entries.0.amount', '50000.00');
    }
}
