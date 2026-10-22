<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\CashMovement;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 065 / US1 — GET /sessions: owner/admin melihat semua shift (aplikasi dan web), pengguna lain hanya miliknya;
 * filter, paginasi, angka per baris dari kueri terkelompok (jumlah kueri tidak naik bersama jumlah baris).
 */
class ShiftListTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $owner;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->outlet = Outlet::first() ?? Outlet::factory()->create();
    }

    private function shift(User $user, array $o = []): CashierSession
    {
        $s = CashierSession::factory()->create(array_merge(['user_id' => $user->id, 'status' => 'open', 'opening_cash' => 100000, 'outlet_id' => $this->outlet->id], $o));

        return $s;
    }

    public function test_owner_sees_every_users_shifts_cashier_sees_only_own(): void
    {
        $dewi = User::factory()->create(['role' => 'cashier', 'name' => 'Dewi']);
        $budi = User::factory()->create(['role' => 'cashier', 'name' => 'Budi']);
        $device = Device::forceCreate(['outlet_id' => $this->outlet->id, 'name' => 'Kasir Depan']);
        $a = $this->shift($dewi, ['device_id' => $device->id]);
        $b = $this->shift($budi);

        $this->actingAs($this->owner, 'sanctum');
        $rows = collect($this->getJson('/api/v1/sessions')->assertOk()->json('data'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $rows->keys()->all());
        $this->assertSame('app', $rows[$a->id]['source']);
        $this->assertSame('Kasir Depan', $rows[$a->id]['terminal_name']);
        $this->assertSame('Dewi', $rows[$a->id]['cashier']['name']);
        $this->assertSame('web', $rows[$b->id]['source']);
        $this->assertNull($rows[$b->id]['terminal_name']);

        $this->actingAs($dewi, 'sanctum');
        $own = $this->getJson('/api/v1/sessions')->assertOk()->json('data');
        $this->assertSame([$a->id], array_column($own, 'id'));
        // `user_id` orang lain dari klien tidak dipercaya: tetap hanya milik sendiri.
        $this->assertSame([$a->id], array_column($this->getJson("/api/v1/sessions?user_id={$budi->id}")->json('data'), 'id'));
    }

    public function test_status_source_and_date_filters_and_pagination(): void
    {
        $u1 = User::factory()->create(['role' => 'cashier']);
        $u2 = User::factory()->create(['role' => 'cashier']);
        $device = Device::forceCreate(['outlet_id' => $this->outlet->id, 'name' => 'T']);
        $open = $this->shift($u1);
        $app = $this->shift($u2, ['device_id' => $device->id]);
        $closed = $this->shift($u1, ['status' => 'closed', 'closed_at' => now(), 'closing_cash' => 100000, 'expected_cash' => 100000, 'cash_difference' => 0]);
        DB::table('cashier_sessions')->where('id', $closed->id)->update(['business_date' => '2026-01-05']);

        $this->actingAs($this->owner, 'sanctum');
        $this->assertEqualsCanonicalizing([$open->id, $app->id], array_column($this->getJson('/api/v1/sessions?status=open')->json('data'), 'id'));
        $this->assertSame([$closed->id], array_column($this->getJson('/api/v1/sessions?status=closed')->json('data'), 'id'));
        $this->assertCount(3, $this->getJson('/api/v1/sessions?status=all')->json('data'));
        $this->assertSame([$app->id], array_column($this->getJson('/api/v1/sessions?status=all&source=app')->json('data'), 'id'));
        $this->assertEqualsCanonicalizing([$open->id, $closed->id], array_column($this->getJson("/api/v1/sessions?status=all&source=web")->json('data'), 'id'));
        $this->assertSame([$closed->id], array_column($this->getJson('/api/v1/sessions?status=all&date_from=2026-01-01&date_to=2026-01-31')->json('data'), 'id'));
        $this->assertSame([$open->id], array_column($this->getJson("/api/v1/sessions?status=all&user_id={$u1->id}&source=web&date_from=2026-02-01")->json('data'), 'id'));

        $page = $this->getJson('/api/v1/sessions?status=all&per_page=2')->assertOk();
        $this->assertCount(2, $page->json('data'));
        $this->assertSame(3, $page->json('meta.total'));
        $this->assertSame(2, $page->json('meta.last_page'));
        // Terbuka dulu, tertutup di belakang.
        $this->assertSame('closed', $this->getJson('/api/v1/sessions?status=all&per_page=3')->json('data.2.status'));
    }

    public function test_rows_carry_sales_cash_in_out_and_the_closed_count(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');
        $shift = $this->shift($cashier);
        $v = $this->makeVariant(stock: 10, price: 20000);
        $this->postJson('/api/v1/orders', [
            'session_id' => $shift->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $v->id, 'qty' => 2]], 'payments' => [['method' => 'cash', 'amount' => 50000]],
        ])->assertCreated();
        CashMovement::forceCreate(['session_id' => $shift->id, 'direction' => 'out', 'reason_code' => 'petty_cash', 'amount' => 5000, 'created_by' => $cashier->id, 'outlet_id' => $shift->outlet_id]);
        CashMovement::forceCreate(['session_id' => $shift->id, 'direction' => 'in', 'reason_code' => 'float_topup', 'amount' => 2000, 'created_by' => $cashier->id, 'outlet_id' => $shift->outlet_id]);

        $row = $this->getJson('/api/v1/sessions')->assertOk()->json('data.0');
        $this->assertSame(1, $row['order_count']);
        $this->assertSame('40000.00', $row['sales_total']);
        $this->assertSame('40000.00', $row['cash_net']);   // 50.000 diserahkan − kembalian 10.000
        $this->assertSame('2000.00', $row['cash_in']);
        $this->assertSame('5000.00', $row['cash_out']);
        // 100.000 modal + 40.000 − 5.000 + 2.000 (rumus tunggal ShiftReportService)
        $this->assertSame('137000.00', $row['expected_cash']);
        $this->assertNull($row['counted_total']);

        $this->postJson("/api/v1/sessions/{$shift->id}/close", ['closing_cash' => 135000], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $closed = $this->getJson('/api/v1/sessions?status=closed')->assertOk()->json('data.0');
        $this->assertSame('135000.00', $closed['counted_total']);
        $this->assertSame('137000.00', $closed['expected_cash']);
        $this->assertSame('-2000.00', $closed['difference']);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_shifts(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        foreach (range(1, 2) as $i) {
            $this->shift(User::factory()->create(['role' => 'cashier']));
        }
        $count = function () {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->getJson('/api/v1/sessions?status=all')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $few = $count();
        foreach (range(1, 6) as $i) {
            $this->shift(User::factory()->create(['role' => 'cashier']));
        }
        $this->assertSame($few, $count(), 'jumlah kueri harus tetap walau shift bertambah');
    }

    public function test_the_outlet_selector_narrows_the_list(): void
    {
        $other = Outlet::factory()->create();
        $cashier = User::factory()->create(['role' => 'cashier']);
        $mine = $this->shift($cashier);
        $theirs = $this->shift(User::factory()->create(['role' => 'cashier']), ['outlet_id' => $other->id]);

        $this->actingAs($this->owner, 'sanctum');
        $this->assertEqualsCanonicalizing([$mine->id, $theirs->id], array_column($this->getJson('/api/v1/sessions')->json('data'), 'id'));
        $this->assertSame([$mine->id], array_column($this->getJson('/api/v1/sessions', ['X-Outlet-Id' => $this->outlet->id])->json('data'), 'id'));
    }

    public function test_invalid_filters_are_422(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $this->getJson('/api/v1/sessions?status=weird')->assertStatus(422);
        $this->getJson('/api/v1/sessions?date_from=05-01-2026')->assertStatus(422);
        $this->getJson('/api/v1/sessions?per_page=1000')->assertStatus(422);
    }
}
