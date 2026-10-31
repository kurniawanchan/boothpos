<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Preorder;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 052-preorder-detail-prev-next — GET /preorders/{preorder}/neighbors.
 *
 * Tetangga WAJIB identik dengan urutan daftar GET /preorders (filter + urutan
 * yang sama), melintasi halaman, dan stabil walau nilai urutannya sama
 * (research.md Decision 1–4). Baris dibuat langsung lewat model karena tes
 * ini hanya membaca; trait HasDataMode tetap men-stempel data_mode.
 */
class PreorderNeighborsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->user, 'sanctum');
    }

    private function makePreorder(array $attrs = [], ?Customer $customer = null): Preorder
    {
        $this->seq++;
        $customer ??= Customer::factory()->create();

        return Preorder::create(array_merge([
            'preorder_number' => sprintf('PO-20261031-%04d', $this->seq),
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'status' => 'ordered',
            'fulfillment' => 'pickup',
            'subtotal' => 100000,
            'total_amount' => 100000,
            'paid_amount' => 0,
        ], $attrs));
    }

    private function listIds(string $query = ''): array
    {
        return collect($this->getJson('/api/v1/preorders?per_page=100'.$query)->assertOk()->json('data'))
            ->pluck('id')->all();
    }

    private function neighbors(int $id, string $query = ''): array
    {
        return $this->getJson("/api/v1/preorders/{$id}/neighbors".($query !== '' ? '?'.ltrim($query, '&') : ''))
            ->assertOk()->json();
    }

    public function test_middle_row_returns_previous_and_next_in_default_order(): void
    {
        $a = $this->makePreorder();
        $b = $this->makePreorder();
        $c = $this->makePreorder();

        // Urutan bawaan created_at desc, id desc → C, B, A.
        $res = $this->neighbors($b->id);

        $this->assertTrue($res['in_list']);
        $this->assertSame(2, $res['position']);
        $this->assertSame(3, $res['total']);
        $this->assertSame(['id' => $c->id, 'preorder_number' => $c->preorder_number], $res['previous']);
        $this->assertSame(['id' => $a->id, 'preorder_number' => $a->preorder_number], $res['next']);
    }

    public function test_first_row_has_no_previous_and_last_row_has_no_next(): void
    {
        $a = $this->makePreorder();
        $this->makePreorder();
        $c = $this->makePreorder();

        $first = $this->neighbors($c->id);
        $this->assertNull($first['previous']);
        $this->assertSame(1, $first['position']);
        $this->assertNotNull($first['next']);

        $last = $this->neighbors($a->id);
        $this->assertNull($last['next']);
        $this->assertSame(3, $last['position']);
        $this->assertNotNull($last['previous']);
    }

    public function test_single_row_has_neither(): void
    {
        $only = $this->makePreorder();

        $res = $this->neighbors($only->id);

        $this->assertTrue($res['in_list']);
        $this->assertSame(1, $res['position']);
        $this->assertNull($res['previous']);
        $this->assertNull($res['next']);
    }

    public function test_neighbors_cross_page_boundaries(): void
    {
        $customer = Customer::factory()->create();
        for ($i = 0; $i < 30; $i++) {
            $this->makePreorder([], $customer);
        }

        $page1 = collect($this->getJson('/api/v1/preorders?per_page=25&page=1')->json('data'))->pluck('id')->all();
        $page2 = collect($this->getJson('/api/v1/preorders?per_page=25&page=2')->json('data'))->pluck('id')->all();

        // page/per_page dikirim tetapi harus diabaikan: posisi dihitung atas seluruh daftar.
        $lastOfPage1 = $this->neighbors($page1[24], 'page=1&per_page=25');
        $this->assertSame(25, $lastOfPage1['position']);
        $this->assertSame($page2[0], $lastOfPage1['next']['id']);

        $firstOfPage2 = $this->neighbors($page2[0], 'page=2&per_page=25');
        $this->assertSame($page1[24], $firstOfPage2['previous']['id']);
    }

    public function test_neighbors_follow_status_filter_and_search(): void
    {
        $siti = Customer::factory()->create(['name' => 'Siti Amalia']);
        $budi = Customer::factory()->create(['name' => 'Budi Santoso']);
        $p1 = $this->makePreorder(['status' => 'dp_paid'], $siti);
        $this->makePreorder(['status' => 'ordered'], $siti);
        $p3 = $this->makePreorder(['status' => 'dp_paid'], $budi);
        $p4 = $this->makePreorder(['status' => 'dp_paid'], $siti);

        // status[]=dp_paid → p4, p3, p1 (baris 'ordered' di antaranya tidak boleh muncul).
        $res = $this->neighbors($p3->id, 'status[]=dp_paid');
        $this->assertSame($p4->id, $res['previous']['id']);
        $this->assertSame($p1->id, $res['next']['id']);
        $this->assertSame(3, $res['total']);

        // + search nama pelanggan → p4, p1.
        $res = $this->neighbors($p4->id, 'status[]=dp_paid&search=siti');
        $this->assertNull($res['previous']);
        $this->assertSame($p1->id, $res['next']['id']);
        $this->assertSame(2, $res['total']);
    }

    public function test_neighbors_follow_every_sort_option(): void
    {
        // Nilai sengaja banyak yang sama (status, fulfillment, nominal, nama,
        // waktu) supaya pengikat preorders.id benar-benar teruji.
        $twins = [Customer::factory()->create(['name' => 'Ani']), Customer::factory()->create(['name' => 'Ani'])];
        $others = [Customer::factory()->create(['name' => 'Zara']), Customer::factory()->create(['name' => 'Bima'])];
        $customers = [...$twins, ...$others];

        for ($i = 0; $i < 12; $i++) {
            $p = $this->makePreorder([
                'status' => $i % 3 === 0 ? 'dp_paid' : 'ordered',
                'fulfillment' => $i % 2 === 0 ? 'pickup' : 'courier',
                'total_amount' => $i % 4 === 0 ? 150000 : 100000,
                'paid_amount' => $i % 5 === 0 ? 50000 : 0,
            ], $customers[$i % 4]);

            DB::table('preorders')->where('id', $p->id)->update([
                'created_at' => $i < 6 ? '2026-10-01 10:00:00' : '2026-10-02 10:00:00',
                'updated_at' => $i % 2 === 0 ? '2026-10-03 10:00:00' : '2026-10-04 10:00:00',
            ]);
        }

        $sorts = ['preorder_number', 'customer_name', 'status', 'fulfillment', 'total_amount', 'outstanding', 'created_at', 'updated_at'];

        foreach ($sorts as $sortBy) {
            foreach (['asc', 'desc'] as $dir) {
                $query = "&sort_by={$sortBy}&sort_dir={$dir}";
                $expected = $this->listIds($query);
                $this->assertCount(12, $expected, "{$sortBy} {$dir}: daftar tidak lengkap");

                $walkedForward = [$expected[0]];
                $current = $expected[0];
                while (($next = $this->neighbors($current, $query)['next']) !== null) {
                    $walkedForward[] = $current = $next['id'];
                    $this->assertLessThanOrEqual(12, count($walkedForward), "{$sortBy} {$dir}: berputar");
                }
                $this->assertSame($expected, $walkedForward, "{$sortBy} {$dir}: Berikutnya menyimpang dari daftar");

                $walkedBack = [$current];
                while (($prev = $this->neighbors($current, $query)['previous']) !== null) {
                    $walkedBack[] = $current = $prev['id'];
                    $this->assertLessThanOrEqual(12, count($walkedBack), "{$sortBy} {$dir}: berputar");
                }
                $this->assertSame(array_reverse($expected), $walkedBack, "{$sortBy} {$dir}: Sebelumnya menyimpang dari daftar");
            }
        }
    }

    public function test_not_in_list_returns_in_list_false_and_null_neighbors(): void
    {
        $ordered = $this->makePreorder(['status' => 'ordered']);
        $this->makePreorder(['status' => 'handed_over']);
        $this->makePreorder(['status' => 'handed_over']);

        $res = $this->neighbors($ordered->id, 'status[]=handed_over');

        $this->assertFalse($res['in_list']);
        $this->assertNull($res['position']);
        $this->assertNull($res['previous']);
        $this->assertNull($res['next']);
        $this->assertSame(2, $res['total']);
    }

    public function test_other_data_mode_preorder_is_404(): void
    {
        $demo = ModeGate::runAs('demo', fn () => $this->makePreorder());

        $this->getJson("/api/v1/preorders/{$demo->id}/neighbors")->assertNotFound();
    }

    public function test_unauthenticated_is_401(): void
    {
        $p = $this->makePreorder();
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/v1/preorders/{$p->id}/neighbors")->assertUnauthorized();
    }

    public function test_endpoint_writes_nothing(): void
    {
        $this->makePreorder();
        $p = $this->makePreorder();
        DB::table('preorders')->where('id', $p->id)->update(['updated_at' => '2026-10-01 10:00:00']);
        $logsBefore = ActivityLog::count();

        $this->neighbors($p->id);

        $this->assertSame($logsBefore, ActivityLog::count());
        $this->assertSame('2026-10-01 10:00:00', DB::table('preorders')->where('id', $p->id)->value('updated_at'));
    }

    public function test_neighbors_payload_has_no_customer_data(): void
    {
        $this->makePreorder();
        $b = $this->makePreorder();
        $this->makePreorder();

        $res = $this->neighbors($b->id);

        $this->assertSame(['id', 'preorder_number'], array_keys($res['previous']));
        $this->assertSame(['id', 'preorder_number'], array_keys($res['next']));
        $this->assertSame(['in_list', 'position', 'total', 'previous', 'next'], array_keys($res));
    }
}
