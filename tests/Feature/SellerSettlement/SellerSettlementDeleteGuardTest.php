<?php

namespace Tests\Feature\SellerSettlement;

use App\Models\Artist;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsSettlementFixtures;
use Tests\TestCase;

/**
 * 076 — event dan seller yang sudah punya settlement tidak boleh dihapus
 * (FK restrict tidak menangkap soft delete, jadi guard ada di controller,
 * mengikuti pola guard hapus Event/Artist yang sudah ada).
 */
class SellerSettlementDeleteGuardTest extends TestCase
{
    use BuildsSettlementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSettlementWorld();
    }

    public function test_an_event_with_a_settlement_cannot_be_deleted(): void
    {
        [$a] = $this->seller('AAA');
        $event = Event::factory()->create(['status' => 'draft']);
        $this->postJson('/api/v1/settlements', ['event_id' => $event->id, 'artist_id' => $a->id])->assertCreated();

        $this->deleteJson("/api/v1/events/{$event->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', __('events_sessions.event_delete_has_settlements'));

        $this->assertNotNull(Event::find($event->id));
    }

    public function test_an_artist_with_a_settlement_cannot_be_deleted(): void
    {
        $artist = Artist::factory()->create(['code' => 'ZZZ']);
        $this->postJson('/api/v1/settlements', ['event_id' => $this->event->id, 'artist_id' => $artist->id])->assertCreated();

        $this->deleteJson("/api/v1/artists/{$artist->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', __('master_data.artist_delete_has_settlements'));

        $this->assertNotNull(Artist::find($artist->id));
    }

    public function test_an_unrelated_event_and_artist_are_still_deletable(): void
    {
        $event = Event::factory()->create(['status' => 'draft']);
        $artist = Artist::factory()->create(['code' => 'YYY']);

        $this->deleteJson("/api/v1/events/{$event->id}")->assertNoContent();
        $this->deleteJson("/api/v1/artists/{$artist->id}")->assertNoContent();
    }
}
