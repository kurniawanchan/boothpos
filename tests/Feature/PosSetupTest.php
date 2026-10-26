<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\Outlet;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 073 / US1 — penyiapan terminal dari tablet TANPA login owner/admin: daftar outlet, daftar terminal per outlet, dan
 * pilih-atau-daftarkan terminal berdasarkan nama. Semua rute di sini sengaja tanpa token (pengecualian Konstitusi IV,
 * plan.md → Complexity Tracking), jadi tes ini juga membuktikan apa yang TIDAK keluar dari rute tersebut.
 */
class PosSetupTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outlet = Outlet::first() ?? Outlet::factory()->create();
    }

    private function terminal(string $name, ?Outlet $outlet = null, bool $revoked = false): Device
    {
        $d = new Device(['name' => $name, 'paired_at' => now()]);
        $d->setAttribute('outlet_id', ($outlet ?? $this->outlet)->id);
        if ($revoked) {
            $d->revoked_at = now();
        }
        $d->save();

        return $d;
    }

    public function test_outlets_are_listed_without_a_token_with_only_id_and_name(): void
    {
        $inactive = Outlet::factory()->create(['is_active' => false]);

        $res = $this->getJson('/api/v1/pos-setup/outlets')->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($this->outlet->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
        $this->assertSame(['id', 'name'], array_keys($res->json('data.0')));
    }

    public function test_outlets_follow_the_current_data_mode(): void
    {
        $demo = ModeGate::runAs('demo', fn () => Outlet::factory()->create());

        $ids = collect($this->getJson('/api/v1/pos-setup/outlets')->json('data'))->pluck('id')->all();

        $this->assertNotContains($demo->id, $ids);
    }

    public function test_terminals_list_only_active_terminals_of_that_outlet_without_metadata(): void
    {
        $other = Outlet::factory()->create();
        $this->terminal('Kasir Depan');
        $this->terminal('Kasir Lama', revoked: true);
        $this->terminal('Kasir Lain Outlet', $other);

        $res = $this->getJson("/api/v1/pos-setup/outlets/{$this->outlet->id}/terminals")->assertOk();

        $this->assertSame(['Kasir Depan'], collect($res->json('data'))->pluck('name')->all());
        $this->assertSame(['id', 'name'], array_keys($res->json('data.0')));
    }

    public function test_an_unknown_or_inactive_outlet_is_404(): void
    {
        $inactive = Outlet::factory()->create(['is_active' => false]);

        $this->getJson('/api/v1/pos-setup/outlets/999999/terminals')->assertNotFound();
        $this->getJson("/api/v1/pos-setup/outlets/{$inactive->id}/terminals")->assertNotFound();
        $this->postJson("/api/v1/pos-setup/outlets/{$inactive->id}/terminals", ['name' => 'X'])->assertNotFound();
        $this->assertSame(0, Device::count());
    }

    public function test_a_new_name_is_registered_under_that_outlet_and_logged_without_an_actor(): void
    {
        $res = $this->postJson("/api/v1/pos-setup/outlets/{$this->outlet->id}/terminals", ['name' => '  Kasir Depan  '])
            ->assertCreated()->assertJsonPath('name', 'Kasir Depan')->assertJsonPath('created', true);

        $device = Device::findOrFail($res->json('id'));
        $this->assertSame($this->outlet->id, (int) $device->outlet_id);
        $this->assertNull($device->paired_by);

        $log = ActivityLog::where('action', 'device_registered')->sole();
        $this->assertNull($log->user_id);
        $this->assertSame($device->id, (int) $log->entity_id);
        $this->assertStringContainsString('Kasir Depan', $log->description);
    }

    public function test_the_same_name_in_any_case_returns_the_existing_terminal_and_creates_nothing(): void
    {
        $first = $this->postJson("/api/v1/pos-setup/outlets/{$this->outlet->id}/terminals", ['name' => 'Kasir Depan'])->assertCreated();

        foreach (['kasir depan', '  KASIR DEPAN ', 'Kasir Depan'] as $again) {
            $this->postJson("/api/v1/pos-setup/outlets/{$this->outlet->id}/terminals", ['name' => $again])
                ->assertOk()->assertJsonPath('id', $first->json('id'))->assertJsonPath('created', false);
        }

        $this->assertSame(1, Device::count());
        $this->assertSame(1, ActivityLog::where('action', 'device_registered')->count());
    }

    public function test_a_blank_or_missing_name_is_422(): void
    {
        $this->postJson("/api/v1/pos-setup/outlets/{$this->outlet->id}/terminals", ['name' => '   '])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson("/api/v1/pos-setup/outlets/{$this->outlet->id}/terminals", [])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson("/api/v1/pos-setup/outlets/{$this->outlet->id}/terminals", ['name' => str_repeat('a', 81)])->assertStatus(422);
        $this->assertSame(0, Device::count());
    }

    public function test_another_outlet_may_reuse_the_name(): void
    {
        $other = Outlet::factory()->create();
        $a = $this->postJson("/api/v1/pos-setup/outlets/{$this->outlet->id}/terminals", ['name' => 'Kasir 1'])->assertCreated();
        $b = $this->postJson("/api/v1/pos-setup/outlets/{$other->id}/terminals", ['name' => 'Kasir 1'])->assertCreated();

        $this->assertNotSame($a->json('id'), $b->json('id'));
        $this->assertSame($other->id, (int) Device::findOrFail($b->json('id'))->outlet_id);
    }

    public function test_the_name_of_a_revoked_terminal_creates_a_new_record(): void
    {
        $old = $this->terminal('Kasir Depan', revoked: true);

        $res = $this->postJson("/api/v1/pos-setup/outlets/{$this->outlet->id}/terminals", ['name' => 'Kasir Depan'])
            ->assertCreated()->assertJsonPath('created', true);

        $this->assertNotSame($old->id, $res->json('id'));
        $this->assertSame(2, Device::count());
    }

    public function test_a_terminal_registered_in_demo_mode_is_not_listed_in_live(): void
    {
        $demoOutlet = ModeGate::runAs('demo', fn () => Outlet::factory()->create());
        ModeGate::runAs('demo', fn () => $this->terminal('Kasir Demo', $demoOutlet));

        // Mode live (bawaan): outlet demo tidak ada, jadi daftar terminalnya pun 404.
        $this->getJson("/api/v1/pos-setup/outlets/{$demoOutlet->id}/terminals")->assertNotFound();
    }

    public function test_registration_is_throttled(): void
    {
        foreach (range(1, 10) as $i) {
            $this->postJson("/api/v1/pos-setup/outlets/{$this->outlet->id}/terminals", ['name' => "T$i"])->assertSuccessful();
        }

        $this->postJson("/api/v1/pos-setup/outlets/{$this->outlet->id}/terminals", ['name' => 'T11'])->assertStatus(429);
    }

    /** 078 — batas bacaan tablet naik dari 30 ke 300/menit (pembatas bernama `tablet-read`, hitungan sendiri); tetap ada sebagai penahan banjir. */
    public function test_listing_is_throttled(): void
    {
        foreach (range(1, 300) as $i) {
            $this->getJson('/api/v1/pos-setup/outlets')->assertOk();
        }

        $this->getJson('/api/v1/pos-setup/outlets')->assertStatus(429);
    }
}
