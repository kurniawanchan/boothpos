<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 048 / T008 (B14) — pendaftaran terminal POS per outlet. */
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

    public function test_code_is_generated_per_outlet_when_omitted(): void
    {
        $this->postJson('/api/v1/devices/pairings', ['name' => 'Tablet kasir'])->assertCreated()->assertJsonPath('code', 'T01')->assertJsonPath('outlet_id', $this->outlet->id);
        $this->postJson('/api/v1/devices/pairings', ['name' => 'Tablet dapur'])->assertCreated()->assertJsonPath('code', 'T02');

        $this->assertDatabaseHas('terminal_counters', ['device_id' => Device::first()->id, 'receipt_seq' => 0]);
        $this->assertSame(2, ActivityLog::where('action', 'device_paired')->count());
    }

    public function test_a_duplicate_code_in_the_same_outlet_is_422(): void
    {
        $this->postJson('/api/v1/devices/pairings', ['name' => 'A', 'code' => 't05'])->assertCreated()->assertJsonPath('code', 'T05');
        $this->postJson('/api/v1/devices/pairings', ['name' => 'B', 'code' => 'T05'])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_the_same_code_may_exist_in_another_outlet(): void
    {
        $other = Outlet::factory()->create();
        $this->postJson('/api/v1/devices/pairings', ['name' => 'A', 'code' => 'T01'], ['X-Outlet-Id' => $this->outlet->id])->assertCreated();
        $this->postJson('/api/v1/devices/pairings', ['name' => 'B', 'code' => 'T01'], ['X-Outlet-Id' => $other->id])->assertCreated()->assertJsonPath('outlet_id', $other->id);
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
        $device = Device::forceCreate(['outlet_id' => $this->outlet->id, 'code' => 'T01', 'name' => 'x']);
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

    public function test_a_revoked_code_is_not_silently_reused(): void
    {
        $id = $this->postJson('/api/v1/devices/pairings', ['name' => 'A'])->json('id');
        $this->deleteJson("/api/v1/devices/{$id}");

        $this->postJson('/api/v1/devices/pairings', ['name' => 'B'])->assertCreated()->assertJsonPath('code', 'T02');
    }

    public function test_list_and_revoke_are_scoped_to_the_selected_outlet(): void
    {
        $other = Outlet::factory()->create();
        $mine = Device::forceCreate(['outlet_id' => $this->outlet->id, 'code' => 'T01', 'name' => 'a']);
        $theirs = Device::forceCreate(['outlet_id' => $other->id, 'code' => 'T01', 'name' => 'b']);

        // X-Outlet-Id menyempit: hanya perangkat outlet itu terlihat, dan perangkat outlet lain 404 (bukan 403/204).
        $ids = collect($this->getJson('/api/v1/devices', ['X-Outlet-Id' => $this->outlet->id])->json('data'))->pluck('id')->all();
        $this->assertSame([$mine->id], $ids);
        $this->deleteJson("/api/v1/devices/{$theirs->id}", [], ['X-Outlet-Id' => $this->outlet->id])->assertNotFound();
        $this->assertNull($theirs->fresh()->revoked_at);
    }

    public function test_an_outlet_assigned_admin_cannot_see_or_revoke_another_outlets_terminals(): void
    {
        $other = Outlet::factory()->create();
        $theirs = Device::forceCreate(['outlet_id' => $other->id, 'code' => 'T01', 'name' => 'b']);
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
