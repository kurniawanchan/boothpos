<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 077 / US4 — catatan kasir untuk SELURUH order (`orders.notes`), ditulis lewat PUT /tabs/{order}/note dan dibaca kembali
 * di detail tab (daftar tab tetap ringkasan). Hanya selagi tab terbuka; log aktivitas ikut transaksi dan tidak menyalin isi catatan.
 */
class TabNoteTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;

    private Order $tab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $session = $this->makeShift($this->cashier);
        $this->tab = $this->makeOpenOrder($session, $this->cashier, ['service_type' => 'takeaway', 'tab_label' => 'A1']);
        $this->addLineTo($this->tab, $this->makeVariant(price: 10_000), 1);
    }

    private function saveNote(array $body, ?Order $tab = null)
    {
        return $this->putJson('/api/v1/tabs/'.($tab ?? $this->tab)->id.'/note', $body);
    }

    public function test_a_note_is_saved_and_returned_on_the_tab_detail(): void
    {
        $this->saveNote(['notes' => 'Es sedikit saja, ambil jam 3'])->assertOk()->assertJsonPath('notes', 'Es sedikit saja, ambil jam 3');

        $this->assertSame('Es sedikit saja, ambil jam 3', $this->tab->fresh()->notes);
        $this->getJson("/api/v1/tabs/{$this->tab->id}")->assertOk()->assertJsonPath('notes', 'Es sedikit saja, ambil jam 3');
        // Daftar tab (GET /tabs) sengaja tetap ringkasan tanpa catatan: tablet membaca catatan dari detail tab.
        $row = collect($this->getJson('/api/v1/tabs')->assertOk()->json('data'))->firstWhere('id', $this->tab->id);
        $this->assertArrayNotHasKey('notes', $row);
    }

    public function test_a_tab_without_a_note_returns_null(): void
    {
        $this->getJson("/api/v1/tabs/{$this->tab->id}")->assertOk()->assertJsonPath('notes', null);
    }

    public function test_a_blank_or_null_note_clears_it(): void
    {
        $this->saveNote(['notes' => 'sementara'])->assertOk();
        $this->saveNote(['notes' => '   '])->assertOk()->assertJsonPath('notes', null);
        $this->assertNull($this->tab->fresh()->notes);

        $this->saveNote(['notes' => 'lagi'])->assertOk();
        $this->saveNote(['notes' => null])->assertOk()->assertJsonPath('notes', null);
    }

    public function test_the_note_must_be_present_and_at_most_1000_characters(): void
    {
        $this->saveNote([])->assertStatus(422)->assertJsonValidationErrors('notes');
        $this->saveNote(['notes' => str_repeat('x', 1001)])->assertStatus(422)->assertJsonValidationErrors('notes');
        $this->saveNote(['notes' => str_repeat('x', 1000)])->assertOk();
    }

    public function test_emoji_newlines_and_special_characters_are_stored_exactly(): void
    {
        $text = "Tanpa gula 🙏\nJangan pakai <es> & \"sirup\"";
        $this->saveNote(['notes' => $text])->assertOk()->assertJsonPath('notes', $text);
        $this->assertSame($text, $this->tab->fresh()->notes);
    }

    public function test_a_paid_or_closed_tab_refuses_note_changes_with_409(): void
    {
        $this->tab->forceFill(['status' => 'completed'])->save();

        $this->saveNote(['notes' => 'terlambat'])->assertStatus(409);
        $this->assertNull($this->tab->fresh()->notes);
    }

    public function test_a_role_without_the_floor_menu_is_refused(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'inventory']), 'sanctum');

        $this->saveNote(['notes' => 'x'])->assertForbidden();
    }

    public function test_a_tab_of_another_outlet_is_not_found_for_a_user_outside_it(): void
    {
        $other = Outlet::factory()->create();
        $this->tab->forceFill(['outlet_id' => $other->id])->save();
        $this->cashier->outlets()->sync([Outlet::where('id', '!=', $other->id)->first()->id]);

        $this->saveNote(['notes' => 'x'])->assertNotFound();
    }

    public function test_an_order_of_the_other_data_mode_is_not_found(): void
    {
        $session = $this->makeShift($this->cashier);
        $demo = ModeGate::runAs('demo', fn () => $this->makeOpenOrder($session, $this->cashier, ['service_type' => 'takeaway', 'tab_label' => 'D1']));

        $this->saveNote(['notes' => 'x'], $demo)->assertNotFound();
    }

    public function test_body_outlet_and_data_mode_are_ignored(): void
    {
        $other = Outlet::factory()->create();
        $before = [$this->tab->outlet_id, $this->tab->data_mode];

        $this->saveNote(['notes' => 'ok', 'outlet_id' => $other->id, 'data_mode' => 'demo'])->assertOk();

        $fresh = $this->tab->fresh();
        $this->assertSame($before, [$fresh->outlet_id, $fresh->data_mode]);
    }

    public function test_the_activity_log_records_the_change_but_not_the_text(): void
    {
        $this->saveNote(['notes' => 'rahasia pelanggan 0812'])->assertOk();
        $this->saveNote(['notes' => 'versi baru'])->assertOk();
        $this->saveNote(['notes' => ''])->assertOk();

        $logs = ActivityLog::where('action', 'tab_note_updated')->orderBy('id')->get();
        $this->assertCount(3, $logs);
        $this->assertStringContainsString('ditambahkan', $logs[0]->description);
        $this->assertStringContainsString('diubah', $logs[1]->description);
        $this->assertStringContainsString('dihapus', $logs[2]->description);
        foreach ($logs as $log) {
            $this->assertStringNotContainsString('rahasia', (string) $log->description);
            $this->assertStringNotContainsString('versi baru', (string) $log->description);
        }
    }

    public function test_repeating_the_same_note_is_idempotent_and_logs_once(): void
    {
        $this->saveNote(['notes' => 'sama'])->assertOk();
        $this->saveNote(['notes' => 'sama'])->assertOk()->assertJsonPath('notes', 'sama');

        $this->assertSame(1, ActivityLog::where('action', 'tab_note_updated')->count());
    }
}
