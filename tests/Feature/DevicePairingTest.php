<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 048 / T008 (B14) — pendaftaran terminal POS per outlet. 064: terminal dikenali lewat NAMA (unik per outlet), tanpa kode. */
class DevicePairingTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outlet = Outlet::first() ?? Outlet::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    public function test_pairing_needs_only_a_name_and_returns_no_code(): void
    {
        $this->postJson('/api/v1/devices/pairings', ['name' => '  Kasir Depan  '])->assertCreated()
            ->assertJsonPath('name', 'Kasir Depan')->assertJsonPath('outlet_id', $this->outlet->id)->assertJsonMissingPath('code');
        $this->postJson('/api/v1/devices/pairings', ['name' => 'Kasir Belakang'])->assertCreated()->assertJsonMissingPath('code');

        $this->assertNull(Device::first()->code, 'kode T01/T02 tidak lagi dibuat');
        $this->assertSame(2, ActivityLog::where('action', 'device_paired')->count());
        $this->assertStringContainsString('Kasir Depan', ActivityLog::where('action', 'device_paired')->first()->description);
    }

    public function test_a_sent_code_is_ignored(): void
    {
        $this->postJson('/api/v1/devices/pairings', ['name' => 'A', 'code' => 'T05'])->assertCreated()->assertJsonMissingPath('code');
        $this->assertNull(Device::first()->code);
    }

    public function test_a_blank_name_is_422(): void
    {
        $this->postJson('/api/v1/devices/pairings', ['name' => '   '])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/devices/pairings', [])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->assertSame(0, Device::count());
    }

    public function test_a_duplicate_name_in_the_same_outlet_is_422_ignoring_case_and_spaces(): void
    {
        $this->postJson('/api/v1/devices/pairings', ['name' => 'Kasir Depan'])->assertCreated();
        $this->postJson('/api/v1/devices/pairings', ['name' => ' kasir depan '])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->assertSame(1, Device::count());
    }

    public function test_the_same_name_may_exist_in_another_outlet(): void
    {
        $other = Outlet::factory()->create();
        $this->postJson('/api/v1/devices/pairings', ['name' => 'Kasir'], ['X-Outlet-Id' => $this->outlet->id])->assertCreated();
        $this->postJson('/api/v1/devices/pairings', ['name' => 'Kasir'], ['X-Outlet-Id' => $other->id])->assertCreated()->assertJsonPath('outlet_id', $other->id);
    }

    public function test_a_revoked_terminal_frees_its_name(): void
    {
        $id = $this->postJson('/api/v1/devices/pairings', ['name' => 'Kasir'])->json('id');
        $this->deleteJson("/api/v1/devices/{$id}")->assertNoContent();

        $this->postJson('/api/v1/devices/pairings', ['name' => 'Kasir'])->assertCreated();
        $this->assertSame(2, Device::count());
    }

    public function test_rename_changes_the_name_and_writes_an_audit_row(): void
    {
        $id = $this->postJson('/api/v1/devices/pairings', ['name' => 'Kasir'])->json('id');

        $this->patchJson("/api/v1/devices/{$id}", ['name' => ' Kasir Depan '])->assertOk()->assertJsonPath('name', 'Kasir Depan')->assertJsonMissingPath('code');

        $this->assertSame('Kasir Depan', Device::find($id)->name);
        $log = ActivityLog::where('action', 'device_renamed')->sole();
        $this->assertStringContainsString('Kasir Depan', $log->description);
    }

    public function test_rename_to_a_duplicate_is_422_but_changing_only_the_case_of_its_own_name_is_ok(): void
    {
        $this->postJson('/api/v1/devices/pairings', ['name' => 'Kasir Depan']);
        $id = $this->postJson('/api/v1/devices/pairings', ['name' => 'Kasir Belakang'])->json('id');

        $this->patchJson("/api/v1/devices/{$id}", ['name' => 'KASIR DEPAN'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->patchJson("/api/v1/devices/{$id}", ['name' => 'kasir belakang'])->assertOk()->assertJsonPath('name', 'kasir belakang');
        $this->patchJson("/api/v1/devices/{$id}", ['name' => ''])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_rename_of_a_revoked_terminal_is_409_and_of_another_outlet_is_404(): void
    {
        $id = $this->postJson('/api/v1/devices/pairings', ['name' => 'Kasir'])->json('id');
        $this->deleteJson("/api/v1/devices/{$id}");
        $this->patchJson("/api/v1/devices/{$id}", ['name' => 'Baru'])->assertStatus(409);

        $other = Outlet::factory()->create();
        $theirs = Device::forceCreate(['outlet_id' => $other->id, 'name' => 'Lain']);
        $this->patchJson("/api/v1/devices/{$theirs->id}", ['name' => 'Baru'], ['X-Outlet-Id' => $this->outlet->id])->assertNotFound();
        $this->assertSame('Lain', $theirs->fresh()->name);
    }

    /** 064 / US3 — catatan aktivitas dan daftar terminal menyebut NAMA, tidak pernah kode T01-style. */
    public function test_the_activity_log_and_the_list_use_names_only(): void
    {
        $id = $this->postJson('/api/v1/devices/pairings', ['name' => 'Kasir Belakang'])->json('id');
        $this->postJson('/api/v1/devices/pairings', ['name' => 'Kasir Depan']);
        $this->patchJson("/api/v1/devices/{$id}", ['name' => 'Dapur']);
        $this->deleteJson("/api/v1/devices/{$id}");

        $logs = ActivityLog::whereIn('action', ['device_paired', 'device_renamed', 'device_revoked'])->get();
        $this->assertCount(4, $logs);
        foreach ($logs as $log) {
            $this->assertDoesNotMatchRegularExpression('/\bT\d{2}\b/', (string) $log->description);
        }
        $this->assertStringContainsString('Kasir Belakang', ActivityLog::where('action', 'device_renamed')->first()->description);
        $this->assertStringContainsString('Dapur', ActivityLog::where('action', 'device_renamed')->first()->description);
        $this->assertStringContainsString('Dapur', ActivityLog::where('action', 'device_revoked')->first()->description);

        $data = $this->getJson('/api/v1/devices')->assertOk()->json('data');
        $this->assertSame(['Dapur', 'Kasir Depan'], array_column($data, 'name'), 'urut menurut nama');
        foreach ($data as $row) {
            $this->assertArrayNotHasKey('code', $row);
        }
    }

    public function test_only_owner_or_admin_may_rename(): void
    {
        $device = Device::forceCreate(['outlet_id' => $this->outlet->id, 'name' => 'x']);
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->patchJson("/api/v1/devices/{$device->id}", ['name' => 'y'])->assertForbidden();
        $this->assertSame('x', $device->fresh()->name);
    }

    public function test_outlet_cannot_be_chosen_from_the_body(): void
    {
        $other = Outlet::factory()->create();

        // Dua outlet aktif + tanpa X-Outlet-Id → admin harus memilih; `outlet_id` di body diabaikan.
        $this->postJson('/api/v1/devices/pairings', ['name' => 'A', 'outlet_id' => $other->id])->assertStatus(422)->assertJsonValidationErrors('outlet');
        $this->postJson('/api/v1/devices/pairings', ['name' => 'A', 'outlet_id' => $other->id], ['X-Outlet-Id' => $this->outlet->id])
            ->assertCreated()->assertJsonPath('outlet_id', $this->outlet->id);
    }

    public function test_only_owner_or_admin_may_pair_list_or_revoke(): void
    {
        $device = Device::forceCreate(['outlet_id' => $this->outlet->id, 'name' => 'x']);
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->postJson('/api/v1/devices/pairings', ['name' => 'A'])->assertForbidden();
        $this->getJson('/api/v1/devices')->assertForbidden();
        $this->deleteJson("/api/v1/devices/{$device->id}")->assertForbidden();
    }

    public function test_revoking_keeps_the_row_for_audit_and_is_idempotent(): void
    {
        $id = $this->postJson('/api/v1/devices/pairings', ['name' => 'A'])->json('id');

        $this->deleteJson("/api/v1/devices/{$id}")->assertNoContent();
        $this->deleteJson("/api/v1/devices/{$id}")->assertNoContent();

        $this->assertNotNull(Device::find($id)->revoked_at);
        $this->assertSame(1, ActivityLog::where('action', 'device_revoked')->count());
        $this->getJson('/api/v1/devices')->assertJsonPath('data.0.revoked', true);
    }

    public function test_list_and_revoke_are_scoped_to_the_selected_outlet(): void
    {
        $other = Outlet::factory()->create();
        $mine = Device::forceCreate(['outlet_id' => $this->outlet->id, 'name' => 'a']);
        $theirs = Device::forceCreate(['outlet_id' => $other->id, 'name' => 'b']);

        // X-Outlet-Id menyempit: hanya perangkat outlet itu terlihat, dan perangkat outlet lain 404 (bukan 403/204).
        $ids = collect($this->getJson('/api/v1/devices', ['X-Outlet-Id' => $this->outlet->id])->json('data'))->pluck('id')->all();
        $this->assertSame([$mine->id], $ids);
        $this->deleteJson("/api/v1/devices/{$theirs->id}", [], ['X-Outlet-Id' => $this->outlet->id])->assertNotFound();
        $this->assertNull($theirs->fresh()->revoked_at);
    }

    public function test_an_outlet_assigned_admin_cannot_see_or_revoke_another_outlets_terminals(): void
    {
        $other = Outlet::factory()->create();
        $theirs = Device::forceCreate(['outlet_id' => $other->id, 'name' => 'b']);
        $admin = User::factory()->create(['role' => 'admin']);
        $role = $admin->role()->first();
        $role->forceFill(['all_outlets' => false])->save();
        $admin->outlets()->sync([$this->outlet->id]);
        $this->actingAs($admin->fresh(), 'sanctum');

        $this->assertSame([], $this->getJson('/api/v1/devices')->json('data'));
        $this->deleteJson("/api/v1/devices/{$theirs->id}")->assertNotFound();
        $this->assertNull($theirs->fresh()->revoked_at);
    }
}
