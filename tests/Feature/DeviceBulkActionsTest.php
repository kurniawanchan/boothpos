<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 079 / US5 (FR-025–FR-031) — aksi massal halaman Terminal. Tiap terminal memakai jalur tunggalnya sendiri, hasilnya
 * per terminal, dan satu penolakan tidak menahan yang lain (BUKAN semua-atau-tidak-sama-sekali).
 */
class DeviceBulkActionsTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private Outlet $a;

    private Outlet $b;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::updateOrCreate(['key' => 'multi_outlet_enabled'], ['value' => '1', 'type' => 'boolean', 'group' => 'licensing']);
        [$this->a, $this->b] = $this->makeOutlets(2)->all();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    private function device(Outlet $outlet, string $name, array $over = []): Device
    {
        return Device::forceCreate(array_merge(['outlet_id' => $outlet->id, 'name' => $name, 'paired_at' => now()], $over));
    }

    private function bulk(string $action, array $ids)
    {
        return $this->postJson('/api/v1/devices/bulk', ['action' => $action, 'ids' => $ids]);
    }

    private function statuses($response): array
    {
        return collect($response->json('data'))->pluck('status', 'id')->all();
    }

    private function logCount(string $action): int
    {
        return ActivityLog::where('action', $action)->count();
    }

    public function test_bulk_deactivate_revokes_active_terminals_skips_the_deactivated_and_logs_each_change(): void
    {
        $x = $this->device($this->a, 'X');
        $y = $this->device($this->b, 'Y');
        $off = $this->device($this->a, 'Sudah Mati', ['revoked_at' => now()]);

        $r = $this->bulk('deactivate', [$x->id, $y->id, $off->id])->assertOk();

        $this->assertSame([$x->id => 'changed', $y->id => 'changed', $off->id => 'skipped'], $this->statuses($r));
        $r->assertJsonPath('summary', ['changed' => 2, 'skipped' => 1, 'refused' => 0, 'not_found' => 0]);
        $this->assertNotNull($x->fresh()->revoked_at);
        $this->assertNotNull($y->fresh()->revoked_at);
        $this->assertSame(2, $this->logCount('device_revoked'));
    }

    public function test_bulk_activate_restores_terminals_and_refuses_one_whose_name_was_taken_meanwhile(): void
    {
        $free = $this->device($this->a, 'Bebas', ['revoked_at' => now()]);
        $clash = $this->device($this->a, 'Kembar', ['revoked_at' => now()]);
        $this->device($this->a, 'kembar'); // nama dilepas saat dicabut, kini dipakai terminal aktif lain
        $active = $this->device($this->b, 'Sudah Aktif');

        $r = $this->bulk('activate', [$free->id, $clash->id, $active->id])->assertOk();

        $this->assertSame([$free->id => 'changed', $clash->id => 'refused', $active->id => 'skipped'], $this->statuses($r));
        $this->assertNull($free->fresh()->revoked_at);
        $this->assertNotNull($clash->fresh()->revoked_at, 'the refused terminal stays deactivated');
        $this->assertNotEmpty(collect($r->json('data'))->firstWhere('id', $clash->id)['reason']);
        $this->assertSame(1, $this->logCount('device_reactivated'));
    }

    public function test_bulk_delete_removes_unused_terminals_and_refuses_the_ones_with_history_without_stopping(): void
    {
        $unused1 = $this->device($this->a, 'Baru 1');
        $used = $this->device($this->a, 'Punya Shift');
        $unused2 = $this->device($this->b, 'Baru 2', ['revoked_at' => now()]);
        $this->shiftAt($this->a, User::factory()->create(['role' => 'cashier']), ['status' => 'closed', 'device_id' => $used->id]);

        // yang ditolak berada DI TENGAH: yang sesudahnya tetap diproses.
        $r = $this->bulk('delete', [$unused1->id, $used->id, $unused2->id])->assertOk();

        $this->assertSame([$unused1->id => 'changed', $used->id => 'refused', $unused2->id => 'changed'], $this->statuses($r));
        $r->assertJsonPath('summary.refused', 1);
        $this->assertNull(Device::withoutGlobalScopes()->find($unused1->id));
        $this->assertNull(Device::withoutGlobalScopes()->find($unused2->id));
        $this->assertNotNull(Device::withoutGlobalScopes()->find($used->id));
        $this->assertSame(2, $this->logCount('device_deleted'));
        $this->assertSame(__('devices.in_use'), collect($r->json('data'))->firstWhere('id', $used->id)['reason']);
    }

    public function test_an_outlet_assigned_admin_cannot_touch_another_outlets_terminals_even_by_id(): void
    {
        $mine = $this->device($this->a, 'Punya A');
        $theirs = $this->device($this->b, 'Punya B');
        $admin = $this->assignUser(User::factory()->create(['role' => 'admin']), [$this->a]);
        $admin->role->forceFill(['all_outlets' => false])->save();
        $this->actingAs($admin->fresh(), 'sanctum');

        foreach (['deactivate', 'delete'] as $action) {
            $r = $this->bulk($action, [$mine->id, $theirs->id, 999999])->assertOk();
            $this->assertSame('not_found', $this->statuses($r)[$theirs->id]);
            $this->assertSame('not_found', $this->statuses($r)[999999]);
            $this->assertNotNull(Device::withoutGlobalScopes()->find($theirs->id), "{$action} must not reach another outlet");
            $this->assertNull(Device::withoutGlobalScopes()->find($theirs->id)->revoked_at);
            if ($action === 'deactivate') {
                $this->assertNotNull($mine->fresh()->revoked_at);
            }
        }
    }

    public function test_the_outlet_is_never_taken_from_the_body(): void
    {
        $theirs = $this->device($this->b, 'Punya B');
        $admin = $this->assignUser(User::factory()->create(['role' => 'admin']), [$this->a]);
        $admin->role->forceFill(['all_outlets' => false])->save();
        $this->actingAs($admin->fresh(), 'sanctum');

        $this->postJson('/api/v1/devices/bulk', ['action' => 'delete', 'ids' => [$theirs->id], 'outlet_id' => $this->b->id])->assertOk();

        $this->assertNotNull(Device::withoutGlobalScopes()->find($theirs->id));
    }

    public function test_cashier_and_inventory_are_refused(): void
    {
        $d = $this->device($this->a, 'Kasir');

        foreach (['cashier', 'inventory'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum');
            foreach (['activate', 'deactivate', 'delete'] as $action) {
                $this->bulk($action, [$d->id])->assertForbidden();
            }
        }
        $this->assertNotNull(Device::withoutGlobalScopes()->find($d->id));
        $this->assertNull($d->fresh()->revoked_at);
    }

    public function test_the_payload_is_validated(): void
    {
        $d = $this->device($this->a, 'Kasir');

        $this->bulk('explode', [$d->id])->assertStatus(422)->assertJsonValidationErrors('action');
        $this->bulk('delete', [])->assertStatus(422)->assertJsonValidationErrors('ids');
        $this->bulk('delete', [$d->id, $d->id])->assertStatus(422)->assertJsonValidationErrors('ids.1');
        $this->bulk('delete', range(1, 101))->assertStatus(422)->assertJsonValidationErrors('ids');
        $this->postJson('/api/v1/devices/bulk', ['action' => 'delete', 'ids' => ['abc']])->assertStatus(422);
        $this->assertNotNull(Device::withoutGlobalScopes()->find($d->id));
    }
}
