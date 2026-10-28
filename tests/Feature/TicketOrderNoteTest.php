<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 078 / US4 — tiket dapur membawa catatan order (`order_note`), dibaca LANGSUNG dari order (tanpa salinan): mengubah catatan sesudah
 * tiket tercetak tidak mencetak ulang otomatis, tetapi pembacaan/cetak ulang berikutnya menampilkan catatan terbaru.
 */
class TicketOrderNoteTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;

    private int $tab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->tab = $this->apiOpenTab($this->makeShift($this->cashier)->id)->json('id');
        $this->apiAddLine($this->tab, $this->makeMenuItem(categoryStation: $this->makeStation('Dapur'))->id, 2, ['note' => 'pedas']);
    }

    public function test_sending_a_tab_with_a_note_returns_tickets_that_carry_it(): void
    {
        $this->putJson("/api/v1/tabs/{$this->tab}/note", ['notes' => 'Alergi kacang'])->assertOk();

        $res = $this->postJson("/api/v1/tabs/{$this->tab}/send")->assertOk();

        $this->assertNotEmpty($res->json('tickets'));
        foreach ($res->json('tickets') as $ticket) {
            $this->assertSame('Alergi kacang', $ticket['order_note']);
            $this->assertSame('pedas', $ticket['items'][0]['note'], 'catatan per item tidak berubah');
        }
    }

    public function test_a_ticket_of_an_order_without_a_note_has_null(): void
    {
        $res = $this->postJson("/api/v1/tabs/{$this->tab}/send")->assertOk();

        $this->assertNull($res->json('tickets.0.order_note'));
    }

    public function test_changing_the_note_later_shows_on_the_next_read_of_the_same_ticket(): void
    {
        $this->putJson("/api/v1/tabs/{$this->tab}/note", ['notes' => 'lama'])->assertOk();
        $ticketId = $this->postJson("/api/v1/tabs/{$this->tab}/send")->assertOk()->json('tickets.0.id');

        $this->putJson("/api/v1/tabs/{$this->tab}/note", ['notes' => 'baru'])->assertOk();

        $this->getJson("/api/v1/tickets/{$ticketId}")->assertOk()->assertJsonPath('order_note', 'baru');
    }

    public function test_the_kitchen_feed_carries_the_note_too(): void
    {
        $this->putJson("/api/v1/tabs/{$this->tab}/note", ['notes' => 'Alergi kacang'])->assertOk();
        $send = $this->postJson("/api/v1/tabs/{$this->tab}/send")->assertOk();
        $stationId = $send->json('tickets.0.station.id');

        $feed = $this->getJson("/api/v1/stations/{$stationId}/tickets")->assertOk()->json();
        $tickets = $feed['tickets'] ?? $feed['data'] ?? [];

        $this->assertNotEmpty($tickets);
        $this->assertSame('Alergi kacang', $tickets[0]['order_note']);
    }
}
