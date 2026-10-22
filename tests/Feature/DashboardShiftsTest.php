<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 065 / US1 — GET /dashboard/shifts: shift terbuka semua pengguna untuk panel dashboard. */
class DashboardShiftsTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outlet = Outlet::first() ?? Outlet::factory()->create();
    }

    private function shift(User $u, array $o = []): CashierSession
    {
        return CashierSession::factory()->create(array_merge(['user_id' => $u->id, 'status' => 'open', 'outlet_id' => $this->outlet->id], $o));
    }

    public function test_one_row_per_open_shift_across_users_newest_first(): void
    {
        $dewi = User::factory()->create(['role' => 'cashier', 'name' => 'Dewi']);
        $budi = User::factory()->create(['role' => 'cashier', 'name' => 'Budi']);
        $device = Device::forceCreate(['outlet_id' => $this->outlet->id, 'name' => 'Kasir Depan']);
        $old = $this->shift($dewi, ['device_id' => $device->id, 'opened_at' => now()->subHours(3)]);
        $new = $this->shift($budi, ['opened_at' => now()->subHour()]);
        $this->shift($budi, ['status' => 'closed', 'closed_at' => now(), 'closing_cash' => 1, 'expected_cash' => 1, 'cash_difference' => 0]);

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $res = $this->getJson('/api/v1/dashboard/shifts')->assertOk();

        $this->assertSame([$new->id, $old->id], array_column($res->json('data'), 'id'));
        $this->assertSame(2, $res->json('total_open'));
        $this->assertSame('Dewi', $res->json('data.1.cashier.name'));
        $this->assertSame('Kasir Depan', $res->json('data.1.terminal_name'));
        $this->assertSame('app', $res->json('data.1.source'));
        $this->assertSame('web', $res->json('data.0.source'));
    }

    public function test_it_is_capped_at_fifty_rows_but_reports_the_true_total(): void
    {
        foreach (range(1, 53) as $i) {
            $this->shift(User::factory()->create(['role' => 'cashier']));
        }
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $res = $this->getJson('/api/v1/dashboard/shifts')->assertOk();
        $this->assertCount(50, $res->json('data'));
        $this->assertSame(53, $res->json('total_open'));
    }

    public function test_a_user_without_the_dashboard_menu_is_refused(): void
    {
        $role = Role::factory()->create(['menu_keys' => ['pos']]);
        $u = User::factory()->create(['role_id' => $role->id]);
        $this->shift($u);
        $this->actingAs($u, 'sanctum');

        $this->getJson('/api/v1/dashboard/shifts')->assertForbidden();
    }

    public function test_a_non_owner_with_the_dashboard_menu_sees_only_their_own_open_shift(): void
    {
        $role = Role::factory()->create(['menu_keys' => ['dashboard']]);
        $me = User::factory()->create(['role_id' => $role->id]);
        $mine = $this->shift($me);
        $this->shift(User::factory()->create(['role' => 'cashier']));
        $this->actingAs($me, 'sanctum');

        $res = $this->getJson('/api/v1/dashboard/shifts')->assertOk();
        $this->assertSame([$mine->id], array_column($res->json('data'), 'id'));
        $this->assertSame(1, $res->json('total_open'));
    }

    public function test_outlet_selector_narrows_the_panel(): void
    {
        $other = Outlet::factory()->create();
        $mine = $this->shift(User::factory()->create(['role' => 'cashier']));
        $this->shift(User::factory()->create(['role' => 'cashier']), ['outlet_id' => $other->id]);

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->assertSame([$mine->id], array_column($this->getJson('/api/v1/dashboard/shifts', ['X-Outlet-Id' => $this->outlet->id])->json('data'), 'id'));
    }
}
