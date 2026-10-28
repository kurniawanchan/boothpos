<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 079 / US5 — manajemen terminal dari web: daftar berfilter dan berhalaman, tambah di outlet pilihan, ganti nama,
 * pindah outlet (ditolak saat ada shift terbuka), nonaktifkan/aktifkan lagi, hapus permanen hanya bila belum
 * pernah dipakai. Rute lama (`DELETE /devices/{id}` = nonaktifkan) tidak berubah — lihat DevicePairingTest.
 */
class DeviceManagementTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private Outlet $a;

    private Outlet $b;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::updateOrCreate(['key' => 'multi_outlet_enabled'], ['value' => '1', 'type' => 'boolean', 'group' => 'licensing']);
        [$this->a, $this->b] = $this->makeOutlets(2)->all();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
    }

    private function device(Outlet $outlet, string $name, array $over = []): Device
    {
        return Device::forceCreate(array_merge(['outlet_id' => $outlet->id, 'name' => $name, 'paired_at' => now()], $over));
    }

    private function logCount(string $action): int
    {
        return ActivityLog::where('action', $action)->count();
    }

    // ---- daftar ----

    public function test_the_paginated_list_filters_by_outlet_status_and_name_and_filters_combine(): void
    {
        $this->device($this->a, 'Kasir Depan');
        $this->device($this->a, 'Kasir Lama', ['revoked_at' => now()]);
        $this->device($this->b, 'Kasir Belakang');
        $this->device($this->b, 'Dapur');

        $all = $this->getJson('/api/v1/devices?page=1&per_page=10')->assertOk();
        $this->assertSame(4, $all->json('meta.total'));
        $this->assertSame($this->a->code, collect($all->json('data'))->firstWhere('name', 'Kasir Depan')['outlet']['code']);

        $names = fn (string $qs) => collect($this->getJson("/api/v1/devices?page=1&{$qs}")->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Dapur', 'Kasir Belakang'], $names("outlet_id[]={$this->b->id}"));
        $this->assertSame(['Kasir Depan'], $names('status=active&outlet_id[]='.$this->a->id));
        $this->assertSame(['Kasir Lama'], $names('status=revoked'));
        $this->assertSame(['Kasir Belakang', 'Kasir Depan', 'Kasir Lama'], $names('q=kasir'));
        $this->assertSame(['Kasir Belakang'], $names("q=BELAKANG&outlet_id[]={$this->b->id}&status=active"));
        $this->assertSame([], $names('q=tidak-ada'));
    }

    public function test_without_page_the_legacy_shape_is_kept(): void
    {
        $this->device($this->a, 'Satu');

        $res = $this->getJson('/api/v1/devices')->assertOk();

        $this->assertNull($res->json('meta'));
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Satu', $res->json('data.0.name'));
    }

    public function test_an_outlet_filter_outside_the_users_outlets_returns_nothing_and_never_widens(): void
    {
        $this->device($this->a, 'Punya A');
        $this->device($this->b, 'Punya B');
        $admin = $this->assignUser(User::factory()->create(['role' => 'admin']), [$this->a]);
        $admin->role->forceFill(['all_outlets' => false])->save();
        $this->actingAs($admin->fresh(), 'sanctum');

        $mine = $this->getJson("/api/v1/devices?page=1&outlet_id[]={$this->a->id}&outlet_id[]={$this->b->id}")->assertOk();
        $this->assertSame(['Punya A'], collect($mine->json('data'))->pluck('name')->all());

        $this->assertSame([], $this->getJson("/api/v1/devices?page=1&outlet_id[]={$this->b->id}")->assertOk()->json('data'));
    }

    // ---- tambah ----

    public function test_a_terminal_can_be_created_in_a_chosen_permitted_outlet_chosen_by_header(): void
    {
        // Pilihan outlet tetap lewat X-Outlet-Id (kontrak 048): Konstitusi IV — request hanya menyempitkan, body tidak membawa outlet.
        $this->postJson('/api/v1/devices/pairings', ['name' => 'Kasir 1'], ['X-Outlet-Id' => $this->b->id])
            ->assertCreated()->assertJsonPath('outlet_id', $this->b->id)->assertJsonPath('outlet.code', $this->b->code);
        $this->assertSame(1, $this->logCount('device_paired'));
    }

    public function test_creating_in_an_outlet_the_user_may_not_use_is_403_and_creates_nothing(): void
    {
        $admin = $this->assignUser(User::factory()->create(['role' => 'admin']), [$this->a]);
        $admin->role->forceFill(['all_outlets' => false])->save();
        $this->actingAs($admin->fresh(), 'sanctum');

        $this->postJson('/api/v1/devices/pairings', ['name' => 'X'], ['X-Outlet-Id' => $this->b->id])->assertForbidden();
        $this->assertSame(0, Device::count());
    }

    public function test_creating_in_an_inactive_outlet_is_refused(): void
    {
        $this->b->update(['is_active' => false]);

        $this->postJson('/api/v1/devices/pairings', ['name' => 'X'], ['X-Outlet-Id' => $this->b->id])->assertForbidden();
        $this->assertSame(0, Device::count());
    }

    public function test_a_body_outlet_id_on_create_is_still_ignored(): void
    {
        // Aturan 048 tetap: outlet dari konteks/header, bukan body. Dua outlet aktif tanpa header → harus memilih (422).
        $this->postJson('/api/v1/devices/pairings', ['name' => 'X', 'outlet_id' => $this->b->id])->assertStatus(422)->assertJsonValidationErrors('outlet');
        $this->assertSame(0, Device::count());
    }

    public function test_the_same_name_is_refused_in_one_outlet_ignoring_case_and_allowed_in_another(): void
    {
        $this->postJson('/api/v1/devices/pairings', ['name' => 'Kasir'], ['X-Outlet-Id' => $this->a->id])->assertCreated();
        $this->postJson('/api/v1/devices/pairings', ['name' => ' kasir '], ['X-Outlet-Id' => $this->a->id])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/devices/pairings', ['name' => 'Kasir'], ['X-Outlet-Id' => $this->b->id])->assertCreated();
    }

    // ---- ganti nama dan pindah ----

    public function test_rename_through_patch_still_works_and_needs_a_field(): void
    {
        $d = $this->device($this->a, 'Lama');

        $this->patchJson("/api/v1/devices/{$d->id}", ['name' => 'Baru'])->assertOk()->assertJsonPath('name', 'Baru');
        $this->patchJson("/api/v1/devices/{$d->id}", [])->assertStatus(422);
        $this->assertSame(1, $this->logCount('device_renamed'));
    }

    public function test_a_terminal_without_an_open_shift_can_move_and_history_keeps_its_outlet(): void
    {
        $d = $this->device($this->a, 'Kasir');
        $variant = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['home_outlet_id' => $this->a->id])->id, 'sell_price' => 10000]);
        $cashier = User::factory()->create(['role' => 'cashier']);
        $shift = $this->shiftAt($this->a, $cashier, ['status' => 'closed', 'device_id' => $d->id]);
        $order = $this->orderAt($this->shiftAt($this->a, $cashier, ['device_id' => $d->id]), $variant);
        $order->forceFill(['device_id' => $d->id])->save();
        \App\Models\CashierSession::query()->where('user_id', $cashier->id)->update(['status' => 'closed']);

        $this->patchJson("/api/v1/devices/{$d->id}", ['outlet_id' => $this->b->id])
            ->assertOk()->assertJsonPath('outlet_id', $this->b->id)->assertJsonPath('name', 'Kasir');

        $this->assertSame($this->b->id, (int) $d->fresh()->outlet_id);
        $this->assertSame($this->a->id, (int) \App\Models\Order::withoutGlobalScopes()->find($order->id)->outlet_id, 'riwayat tetap di outlet transaksinya');
        $this->assertSame($this->a->id, (int) \App\Models\CashierSession::withoutGlobalScopes()->find($shift->id)->outlet_id);
        $this->assertSame(1, $this->logCount('device_moved'));
        $log = ActivityLog::where('action', 'device_moved')->first();
        $this->assertSame($this->a->id, (int) $log->old_values['outlet_id']);
        $this->assertSame($this->b->id, (int) $log->new_values['outlet_id']);
    }

    public function test_a_terminal_with_an_open_shift_cannot_move(): void
    {
        $d = $this->device($this->a, 'Kasir');
        $this->shiftAt($this->a, User::factory()->create(['role' => 'cashier']), ['device_id' => $d->id]);

        $this->patchJson("/api/v1/devices/{$d->id}", ['outlet_id' => $this->b->id])->assertStatus(409);

        $this->assertSame($this->a->id, (int) $d->fresh()->outlet_id);
        $this->assertSame(0, $this->logCount('device_moved'));
    }

    public function test_a_move_is_refused_when_the_name_is_taken_in_the_target_outlet(): void
    {
        $d = $this->device($this->a, 'Kasir');
        $this->device($this->b, 'KASIR');

        $this->patchJson("/api/v1/devices/{$d->id}", ['outlet_id' => $this->b->id])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->assertSame($this->a->id, (int) $d->fresh()->outlet_id);
    }

    public function test_a_move_can_rename_in_the_same_request_and_checks_the_new_name_in_the_target(): void
    {
        $d = $this->device($this->a, 'Kasir');
        $this->device($this->b, 'Kasir');

        $this->patchJson("/api/v1/devices/{$d->id}", ['outlet_id' => $this->b->id, 'name' => 'Kasir 2'])
            ->assertOk()->assertJsonPath('name', 'Kasir 2')->assertJsonPath('outlet_id', $this->b->id);
    }

    public function test_a_move_to_an_inactive_or_unpermitted_outlet_is_403(): void
    {
        $d = $this->device($this->a, 'Kasir');
        $this->b->update(['is_active' => false]);

        $this->patchJson("/api/v1/devices/{$d->id}", ['outlet_id' => $this->b->id])->assertForbidden();
        $this->assertSame($this->a->id, (int) $d->fresh()->outlet_id);
    }

    public function test_a_revoked_terminal_cannot_be_moved_or_renamed(): void
    {
        $d = $this->device($this->a, 'Kasir', ['revoked_at' => now()]);

        $this->patchJson("/api/v1/devices/{$d->id}", ['outlet_id' => $this->b->id])->assertStatus(409);
        $this->patchJson("/api/v1/devices/{$d->id}", ['name' => 'Baru'])->assertStatus(409);
    }

    // ---- aktifkan lagi ----

    public function test_a_revoked_terminal_can_be_reactivated_when_its_name_is_free(): void
    {
        $d = $this->device($this->a, 'Kasir', ['revoked_at' => now()]);

        $this->postJson("/api/v1/devices/{$d->id}/reactivate")->assertOk()->assertJsonPath('revoked', false);

        $this->assertNull($d->fresh()->revoked_at);
        $this->assertSame(1, $this->logCount('device_reactivated'));
    }

    public function test_reactivating_is_refused_when_an_active_terminal_has_the_name(): void
    {
        $d = $this->device($this->a, 'Kasir', ['revoked_at' => now()]);
        $this->device($this->a, 'kasir');

        $this->postJson("/api/v1/devices/{$d->id}/reactivate")->assertStatus(422)->assertJsonValidationErrors('name');
        $this->assertNotNull($d->fresh()->revoked_at);
    }

    public function test_a_terminal_of_an_inactive_outlet_is_hidden_so_it_cannot_be_reactivated_or_listed(): void
    {
        $d = $this->device($this->a, 'Kasir', ['revoked_at' => now()]);
        $this->a->update(['is_active' => false]);

        $this->postJson("/api/v1/devices/{$d->id}/reactivate")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/devices?page=1')->json('data'));
        $this->assertNotNull($d->fresh()->revoked_at);
    }

    public function test_reactivating_an_active_terminal_is_a_no_op_without_a_log(): void
    {
        $d = $this->device($this->a, 'Kasir');

        $this->postJson("/api/v1/devices/{$d->id}/reactivate")->assertOk();
        $this->assertSame(0, $this->logCount('device_reactivated'));
    }

    public function test_a_deactivated_terminal_is_refused_by_its_tablet_and_works_again_after_reactivation(): void
    {
        $d = $this->device($this->a, 'Kasir');
        $cashier = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$this->a]);

        $this->deleteJson("/api/v1/devices/{$d->id}")->assertNoContent();
        $this->actingAs($cashier, 'sanctum');
        $this->postJson('/api/v1/devices/heartbeat', ['app_version' => '1', 'clock_ms' => now()->getTimestampMs(), 'queue_depth' => 0], ['X-Device-Id' => $d->id])
            ->assertStatus(422)->assertJsonValidationErrors('device_id');

        $this->actingAs($this->owner, 'sanctum');
        $this->postJson("/api/v1/devices/{$d->id}/reactivate")->assertOk();
        $this->actingAs($cashier, 'sanctum');
        $this->postJson('/api/v1/devices/heartbeat', ['app_version' => '1', 'clock_ms' => now()->getTimestampMs(), 'queue_depth' => 0], ['X-Device-Id' => $d->id])->assertOk();
    }

    // ---- hapus permanen ----

    public function test_an_unused_terminal_can_be_deleted_permanently_and_is_logged(): void
    {
        $d = $this->device($this->a, 'Kasir');

        $this->deleteJson("/api/v1/devices/{$d->id}/permanent")->assertNoContent();

        $this->assertNull(Device::withoutGlobalScopes()->find($d->id));
        $this->assertSame(1, $this->logCount('device_deleted'));
    }

    public function test_a_terminal_with_an_order_or_a_shift_cannot_be_deleted(): void
    {
        $withShift = $this->device($this->a, 'Punya Shift');
        $this->shiftAt($this->a, User::factory()->create(['role' => 'cashier']), ['status' => 'closed', 'device_id' => $withShift->id]);

        $withOrder = $this->device($this->a, 'Punya Order');
        $variant = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['home_outlet_id' => $this->a->id])->id, 'sell_price' => 10000]);
        $order = $this->orderAt($this->shiftAt($this->a, User::factory()->create(['role' => 'cashier'])), $variant);
        $order->forceFill(['device_id' => $withOrder->id])->save();

        foreach ([$withShift, $withOrder] as $d) {
            $this->deleteJson("/api/v1/devices/{$d->id}/permanent")->assertStatus(409);
            $this->assertNotNull(Device::withoutGlobalScopes()->find($d->id));
        }
        $this->assertSame(0, $this->logCount('device_deleted'));
    }

    // ---- hak akses ----

    public function test_only_owner_and_admin_may_use_the_new_routes(): void
    {
        $d = $this->device($this->a, 'Kasir');

        foreach (['cashier', 'inventory'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum');
            $this->getJson('/api/v1/devices?page=1')->assertForbidden();
            $this->postJson('/api/v1/devices/pairings', ['name' => 'X'])->assertForbidden();
            $this->patchJson("/api/v1/devices/{$d->id}", ['name' => 'Y'])->assertForbidden();
            $this->postJson("/api/v1/devices/{$d->id}/reactivate")->assertForbidden();
            $this->deleteJson("/api/v1/devices/{$d->id}/permanent")->assertForbidden();
        }
        $this->assertSame('Kasir', $d->fresh()->name);
    }

    public function test_an_outlet_assigned_admin_gets_404_for_another_outlets_terminal(): void
    {
        $theirs = $this->device($this->b, 'Punya B');
        $admin = $this->assignUser(User::factory()->create(['role' => 'admin']), [$this->a]);
        $admin->role->forceFill(['all_outlets' => false])->save();
        $this->actingAs($admin->fresh(), 'sanctum');

        $this->patchJson("/api/v1/devices/{$theirs->id}", ['name' => 'Z'])->assertNotFound();
        $this->postJson("/api/v1/devices/{$theirs->id}/reactivate")->assertNotFound();
        $this->deleteJson("/api/v1/devices/{$theirs->id}/permanent")->assertNotFound();
    }
}
