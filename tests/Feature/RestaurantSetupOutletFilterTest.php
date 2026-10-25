<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 071 (US1) — daftar Meja dan Stasiun menerima `outlet_id[]`: dipotong dengan outlet yang boleh dilihat (Konstitusi IV),
 * tidak pernah memperluas, dan tanpa parameter perilaku lama tetap.
 */
class RestaurantSetupOutletFilterTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private $a;

    private $b;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->a, $this->b] = $this->makeOutlets(2)->all();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    private function table(string $name, $outlet): DiningTable
    {
        return DiningTable::factory()->create(['name' => $name, 'outlet_id' => $outlet->id]);
    }

    private function station(string $name, $outlet): Station
    {
        $station = new Station(['name' => $name]);
        $station->outlet_id = $outlet->id;
        $station->save();

        return $station;
    }

    private function names(string $url): array
    {
        return collect($this->getJson($url)->assertOk()->json('data'))->pluck('name')->sort()->values()->all();
    }

    public function test_tables_can_be_filtered_to_one_or_two_outlets_without_duplicates(): void
    {
        $this->table('D1', $this->a);
        $this->table('T1', $this->b);
        $this->table('T2', $this->b);

        $this->assertSame(['D1'], $this->names("/api/v1/dining-tables?outlet_id[]={$this->a->id}"));
        $this->assertSame(['T1', 'T2'], $this->names("/api/v1/dining-tables?outlet_id[]={$this->b->id}"));
        $this->assertSame(['D1', 'T1', 'T2'], $this->names("/api/v1/dining-tables?outlet_id[]={$this->a->id}&outlet_id[]={$this->b->id}&outlet_id[]={$this->a->id}"));
    }

    public function test_stations_can_be_filtered_to_one_or_two_outlets_without_duplicates(): void
    {
        $this->station('Bar', $this->a);
        $this->station('Dapur', $this->b);

        $this->assertSame(['Bar'], $this->names("/api/v1/stations?outlet_id[]={$this->a->id}"));
        $this->assertSame(['Dapur'], $this->names("/api/v1/stations?outlet_id[]={$this->b->id}"));
        $this->assertSame(['Bar', 'Dapur'], $this->names("/api/v1/stations?outlet_id[]={$this->a->id}&outlet_id[]={$this->b->id}"));
    }

    public function test_without_the_parameter_every_visible_outlet_is_listed_and_rows_carry_their_outlet(): void
    {
        $this->table('D1', $this->a);
        $this->station('Dapur', $this->b);

        $tables = $this->getJson('/api/v1/dining-tables')->assertOk()->json('data');
        $stations = $this->getJson('/api/v1/stations')->assertOk()->json('data');

        $this->assertSame([$this->a->id], collect($tables)->pluck('outlet_id')->all());
        $this->assertSame([$this->b->id], collect($stations)->pluck('outlet_id')->all());
    }

    public function test_outlets_outside_the_users_assignment_are_cut_off_not_an_error(): void
    {
        $this->table('D1', $this->a);
        $this->table('T1', $this->b);
        $this->station('Bar', $this->a);
        $this->station('Dapur', $this->b);
        $user = $this->assignUser(User::factory()->create(['role' => 'inventory']), [$this->b]);
        $this->actingAs($user, 'sanctum');

        $this->assertSame([], $this->names("/api/v1/dining-tables?outlet_id[]={$this->a->id}"));
        $this->assertSame(['T1'], $this->names("/api/v1/dining-tables?outlet_id[]={$this->a->id}&outlet_id[]={$this->b->id}"));
        $this->assertSame([], $this->names("/api/v1/stations?outlet_id[]={$this->a->id}"));
        $this->assertSame(['Dapur'], $this->names("/api/v1/stations?outlet_id[]={$this->a->id}&outlet_id[]={$this->b->id}"));
    }

    public function test_the_header_outlet_still_narrows_and_the_filter_cannot_widen_it(): void
    {
        $this->table('D1', $this->a);
        $this->table('T1', $this->b);
        $both = "outlet_id[]={$this->a->id}&outlet_id[]={$this->b->id}";

        $json = $this->withHeader('X-Outlet-Id', (string) $this->b->id)->getJson("/api/v1/dining-tables?{$both}")->assertOk()->json('data');

        $this->assertSame(['T1'], collect($json)->pluck('name')->all());
    }

    public function test_a_non_integer_outlet_is_refused(): void
    {
        $this->getJson('/api/v1/dining-tables?outlet_id[]=abc')->assertStatus(422);
        $this->getJson('/api/v1/stations?outlet_id[]=abc')->assertStatus(422);
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_rows(): void
    {
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson("/api/v1/dining-tables?outlet_id[]={$this->a->id}")->assertOk();
            $this->getJson("/api/v1/stations?outlet_id[]={$this->a->id}")->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $this->table('D1', $this->a);
        $this->station('Bar', $this->a);
        $count(); // pemanasan
        $few = $count();
        foreach (range(2, 9) as $i) {
            $this->table("D{$i}", $this->a);
            $this->station("S{$i}", $this->a);
        }

        $this->assertSame($few, $count());
    }
}
