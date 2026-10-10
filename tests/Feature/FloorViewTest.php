<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 028 US1 — GET /floor (FR-003): meja per area, kosong/terisi, tab ringkas,
 * jumlah query konstan, dan token versi untuk polling (research Keputusan 6).
 */
class FloorViewTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
    }

    private function tab(DiningTable $table, array $over = []): Order
    {
        static $session = null;
        if ($session === null || $session->fresh() === null) {
            $session = $this->makeShift($this->cashier);
        }

        return $this->makeOpenOrder($session, $this->cashier, array_merge([
            'table_id' => $table->id, 'service_type' => 'dine_in',
        ], $over));
    }

    public function test_tables_are_grouped_by_area_with_unnamed_area_last(): void
    {
        DiningTable::factory()->inArea('Terrace')->create(['name' => 'T1']);
        DiningTable::factory()->inArea('Indoor')->create(['name' => '2']);
        DiningTable::factory()->inArea('Indoor')->create(['name' => '1']);
        DiningTable::factory()->create(['name' => 'Bar']);

        $areas = $this->getJson('/api/v1/floor')->assertOk()->json('areas');

        $this->assertSame(['Indoor', 'Terrace', ''], collect($areas)->pluck('area')->all());
        $this->assertSame(['1', '2'], collect($areas[0]['tables'])->pluck('name')->all());
    }

    public function test_free_and_occupied_tables_carry_the_agreed_shape(): void
    {
        $free = DiningTable::factory()->create(['name' => 'free']);
        $busy = DiningTable::factory()->create(['name' => 'busy']);
        $order = $this->tab($busy, ['total_amount' => 125000]);
        $this->addLineTo($order, $this->makeVariant(), 2);

        $tables = collect($this->getJson('/api/v1/floor')->json('areas.0.tables'))->keyBy('name');

        $this->assertSame('free', $tables['free']['state']);
        $this->assertNull($tables['free']['tab']);

        $this->assertSame('occupied', $tables['busy']['state']);
        $tab = $tables['busy']['tab'];
        $this->assertSame($order->id, $tab['id']);
        $this->assertSame($order->order_number, $tab['order_number']);
        $this->assertSame('125000.00', $tab['total_amount']);
        $this->assertSame($order->session_id, $tab['session_id']);
        $this->assertSame(1, $tab['unsent_count']);
        $this->assertSame(0, $tab['ready_count']);
        $this->assertArrayHasKey('opened_at', $tab);
    }

    public function test_inactive_tables_and_finished_tabs_are_not_shown_as_occupied(): void
    {
        DiningTable::factory()->inactive()->create(['name' => 'off']);
        $table = DiningTable::factory()->create(['name' => 'done']);
        $this->tab($table, ['status' => 'completed']);

        $tables = collect($this->getJson('/api/v1/floor')->json('areas.0.tables'));

        $this->assertSame(['done'], $tables->pluck('name')->all());
        $this->assertSame('free', $tables[0]['state']);
    }

    public function test_takeaway_tabs_are_listed_separately(): void
    {
        $session = $this->makeShift($this->cashier);
        $takeaway = $this->makeOpenOrder($session, $this->cashier, ['service_type' => 'takeaway', 'tab_label' => 'Budi']);

        $rows = $this->getJson('/api/v1/floor')->json('takeaway_tabs');

        $this->assertCount(1, $rows);
        $this->assertSame($takeaway->id, $rows[0]['id']);
        $this->assertSame('Budi', $rows[0]['tab_label']);
    }

    public function test_tabs_of_the_other_data_mode_never_appear(): void
    {
        $table = ModeGate::runAs('demo', fn () => DiningTable::factory()->create(['name' => 'demo-table']));
        $this->assertNotNull($table);

        $this->assertSame([], $this->getJson('/api/v1/floor')->json('areas'));
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_tables(): void
    {
        $queries = function (): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/floor')->assertOk();
            $log = array_map(fn ($q) => $q['query'], DB::getQueryLog());
            DB::disableQueryLog();

            return $log;
        };

        // Panggilan pertama selisih SATU query konstan dari panggilan berikutnya
        // (penyebab pastinya tidak diselidiki — tidak bergantung pada jumlah meja:
        // terbukti 3 vs 43 meja menghasilkan jumlah query yang sama). Dibuang di
        // sini supaya yang dibandingkan hanya pekerjaan per-meja.
        $queries();

        foreach (range(1, 3) as $i) {
            $this->tab(DiningTable::factory()->create(['name' => "a$i"]));
        }
        $small = $queries();

        foreach (range(1, 40) as $i) {
            $this->tab(DiningTable::factory()->create(['name' => "b$i"]));
        }
        $large = $queries();

        $this->assertSame(
            count($small), count($large),
            "GET /floor menjalankan query per meja (N+1).\nKecil:\n".implode("\n", $small)."\nBesar:\n".implode("\n", $large)
        );
    }

    public function test_an_unchanged_floor_short_circuits_on_the_version_token(): void
    {
        $table = DiningTable::factory()->create();
        $order = $this->tab($table);

        $first = $this->getJson('/api/v1/floor')->assertOk()->json();
        $this->assertNotEmpty($first['version']);

        $this->getJson('/api/v1/floor?version='.$first['version'])
            ->assertOk()->assertExactJson(['unchanged' => true, 'version' => $first['version']]);

        // Perubahan APA PUN yang terlihat di lantai mengubah token — termasuk
        // yang terjadi dalam detik yang sama (token bukan dari timestamp).
        $order->update(['total_amount' => 5000]);
        $second = $this->getJson('/api/v1/floor?version='.$first['version'])->assertOk()->json();

        $this->assertArrayNotHasKey('unchanged', $second);
        $this->assertNotSame($first['version'], $second['version']);
    }

    public function test_a_stale_or_garbage_version_returns_the_full_floor(): void
    {
        DiningTable::factory()->create();

        $this->getJson('/api/v1/floor?version=nonsense')->assertOk()->assertJsonStructure(['areas', 'takeaway_tabs', 'version']);
    }

    public function test_only_floor_permission_holders_may_view_the_floor(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'inventory']), 'sanctum');
        $this->getJson('/api/v1/floor')->assertForbidden();
    }
}
