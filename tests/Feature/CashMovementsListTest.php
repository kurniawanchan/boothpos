<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\CashMovement;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 065 / US2 — GET /cash-movements: kas masuk/keluar lintas shift untuk web. Aturan lihat sama dengan daftar shift;
 * total mencakup seluruh hasil yang disaring; per-shift `summary` setuju dengan jumlah baris daftar (FR-007).
 */
class CashMovementsListTest extends TestCase
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
        return CashierSession::factory()->create(array_merge(['user_id' => $u->id, 'status' => 'open', 'opening_cash' => 100000, 'outlet_id' => $this->outlet->id], $o));
    }

    private function move(CashierSession $s, string $dir, string $reason, float $amount, ?string $note = null): CashMovement
    {
        return CashMovement::forceCreate(['session_id' => $s->id, 'direction' => $dir, 'reason_code' => $reason, 'reason_note' => $note, 'amount' => $amount, 'created_by' => $s->user_id, 'outlet_id' => $s->outlet_id]);
    }

    public function test_owner_sees_all_movements_with_labels_terminal_and_source_cashier_only_own(): void
    {
        $dewi = User::factory()->create(['role' => 'cashier', 'name' => 'Dewi']);
        $budi = User::factory()->create(['role' => 'cashier', 'name' => 'Budi']);
        $device = Device::forceCreate(['outlet_id' => $this->outlet->id, 'name' => 'Kasir Depan']);
        $a = $this->shift($dewi, ['device_id' => $device->id]);
        $b = $this->shift($budi);
        $m1 = $this->move($a, 'out', 'petty_cash', 50000);
        $m2 = $this->move($b, 'in', 'float_topup', 20000);

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $rows = collect($this->getJson('/api/v1/cash-movements')->assertOk()->json('data'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$m1->id, $m2->id], $rows->keys()->all());
        $this->assertSame('Uang kecil / belanja', $rows[$m1->id]['reason_label']);
        $this->assertSame('Kasir Depan', $rows[$m1->id]['terminal_name']);
        $this->assertSame('app', $rows[$m1->id]['source']);
        $this->assertSame('web', $rows[$m2->id]['source']);
        $this->assertSame('50000.00', $rows[$m1->id]['amount']);

        $this->actingAs($dewi, 'sanctum');
        $this->assertSame([$m1->id], array_column($this->getJson('/api/v1/cash-movements')->json('data'), 'id'));
        $this->assertSame([$m1->id], array_column($this->getJson("/api/v1/cash-movements?user_id={$budi->id}")->json('data'), 'id'), 'user_id orang lain tidak dipercaya');
    }

    public function test_filters_and_totals_cover_the_whole_filtered_set_not_just_the_page(): void
    {
        $u = User::factory()->create(['role' => 'cashier']);
        $s1 = $this->shift($u);
        $s2 = $this->shift($u, ['status' => 'closed', 'closed_at' => now(), 'closing_cash' => 0, 'expected_cash' => 0, 'cash_difference' => 0]);
        DB::table('cashier_sessions')->where('id', $s2->id)->update(['business_date' => '2026-01-10']);
        foreach (range(1, 5) as $i) {
            $this->move($s1, 'out', 'supplier', 1000 * $i);
        }
        $this->move($s1, 'in', 'float_topup', 7000);
        $this->move($s2, 'out', 'bank_deposit', 9000);

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $page = $this->getJson('/api/v1/cash-movements?per_page=2')->assertOk();
        $this->assertCount(2, $page->json('data'));
        $this->assertSame(7, $page->json('meta.total'));
        $this->assertSame('7000.00', $page->json('totals.in'));
        $this->assertSame('24000.00', $page->json('totals.out'));   // 1+2+3+4+5 ribu + 9 ribu, seluruh himpunan

        $out = $this->getJson('/api/v1/cash-movements?direction=out');
        $this->assertSame(6, $out->json('meta.total'));
        $this->assertSame('0.00', $out->json('totals.in'));

        $bySession = $this->getJson("/api/v1/cash-movements?session_id={$s2->id}");
        $this->assertSame(['bank_deposit'], array_column($bySession->json('data'), 'reason_code'));

        $byDate = $this->getJson('/api/v1/cash-movements?date_from=2026-01-01&date_to=2026-01-31');
        $this->assertSame(1, $byDate->json('meta.total'));
        $this->assertSame('9000.00', $byDate->json('totals.out'));
    }

    public function test_the_per_shift_summary_agrees_with_the_listed_rows(): void
    {
        $u = User::factory()->create(['role' => 'cashier']);
        $s = $this->shift($u);
        $this->move($s, 'out', 'petty_cash', 12000);
        $this->move($s, 'out', 'supplier', 3000);
        $this->move($s, 'in', 'float_topup', 5000);

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $list = $this->getJson("/api/v1/cash-movements?session_id={$s->id}")->assertOk();
        $summary = $this->getJson("/api/v1/sessions/{$s->id}/summary")->assertOk();

        $this->assertSame($summary->json('cash_in'), $list->json('totals.in'));
        $this->assertSame($summary->json('cash_out'), $list->json('totals.out'));
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_rows(): void
    {
        $u = User::factory()->create(['role' => 'cashier']);
        $s = $this->shift($u);
        $this->move($s, 'in', 'float_topup', 1);
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $count = function () {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->getJson('/api/v1/cash-movements?per_page=100')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $few = $count();
        foreach (range(1, 10) as $i) {
            $this->move($this->shift(User::factory()->create(['role' => 'cashier'])), 'out', 'other', 100, 'x');
        }
        $this->assertSame($few, $count());
    }

    public function test_outlet_selector_and_invalid_filters(): void
    {
        $other = Outlet::factory()->create();
        $mine = $this->move($this->shift(User::factory()->create(['role' => 'cashier'])), 'in', 'float_topup', 1);
        $this->move($this->shift(User::factory()->create(['role' => 'cashier']), ['outlet_id' => $other->id]), 'in', 'float_topup', 1);

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->assertSame([$mine->id], array_column($this->getJson('/api/v1/cash-movements', ['X-Outlet-Id' => $this->outlet->id])->json('data'), 'id'));
        $this->getJson('/api/v1/cash-movements?direction=sideways')->assertStatus(422);
        $this->getJson('/api/v1/cash-movements?date_to=nope')->assertStatus(422);
    }
}
