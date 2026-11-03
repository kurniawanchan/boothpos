<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 090 / US7 — daftar meja: paginasi dan filter OPT-IN. Tanpa `page` bentuk lama tidak berubah (lantai, tab, dan POS
 * bergantung padanya); dengan `page` datang amplop {data, meta} + daftar area untuk filter.
 */
class DiningTableListTest extends TestCase
{
    use OutletFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'inventory']), 'sanctum'); // punya `dining_setup`
    }

    private function names($res): array
    {
        return collect($res->json('data'))->pluck('name')->all();
    }

    public function test_without_page_the_old_shape_lists_everything(): void
    {
        DiningTable::factory()->count(30)->create();

        $res = $this->getJson('/api/v1/dining-tables')->assertOk();

        $this->assertCount(30, $res->json('data'));
        $this->assertNull($res->json('meta'), 'bentuk lama tidak membawa meta');
        $res->assertJsonStructure(['data' => [['id', 'name', 'area', 'seats', 'is_active', 'has_open_tab']]]);
    }

    public function test_the_old_active_filter_still_works_without_paging(): void
    {
        DiningTable::factory()->create(['name' => 'A', 'is_active' => true]);
        DiningTable::factory()->create(['name' => 'B', 'is_active' => false]);

        $this->assertSame(['A'], $this->names($this->getJson('/api/v1/dining-tables?active=1')->assertOk()));
    }

    public function test_with_page_the_envelope_carries_meta_and_the_areas(): void
    {
        foreach (range(1, 30) as $n) {
            DiningTable::factory()->inArea($n % 2 === 0 ? 'Indoor' : 'Teras')->create(['name' => sprintf('T%02d', $n)]);
        }
        DiningTable::factory()->create(['name' => 'Tanpa area']); // area kosong tidak masuk daftar area

        $res = $this->getJson('/api/v1/dining-tables?page=2&per_page=10')->assertOk();

        $res->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.per_page', 10)->assertJsonPath('meta.total', 31)->assertJsonPath('meta.last_page', 4);
        $this->assertCount(10, $res->json('data'));
        $this->assertSame(['Indoor', 'Teras'], $res->json('meta.areas'));
    }

    public function test_per_page_is_capped_at_100_and_defaults_sanely(): void
    {
        DiningTable::factory()->count(120)->create();

        $this->assertCount(100, $this->getJson('/api/v1/dining-tables?page=1&per_page=500')->assertOk()->json('data'));
        $this->assertCount(25, $this->getJson('/api/v1/dining-tables?page=1')->assertOk()->json('data'));
        $this->getJson('/api/v1/dining-tables?page=0')->assertStatus(422);
        $this->getJson('/api/v1/dining-tables?page=1&per_page=0')->assertStatus(422);
    }

    public function test_filters_by_search_area_and_status_and_combines_them(): void
    {
        DiningTable::factory()->inArea('Indoor')->create(['name' => 'A1']);
        DiningTable::factory()->inArea('Indoor')->create(['name' => 'A2', 'is_active' => false]);
        DiningTable::factory()->inArea('Teras')->create(['name' => 'T1']);
        DiningTable::factory()->inArea('Teras')->create(['name' => 'T2', 'is_active' => false]);

        $get = fn (string $qs) => $this->names($this->getJson('/api/v1/dining-tables?page=1&'.$qs)->assertOk());

        $this->assertEqualsCanonicalizing(['A1', 'A2'], $get('area=Indoor'));
        $this->assertEqualsCanonicalizing(['A2', 'T2'], $get('is_active=0'));
        $this->assertEqualsCanonicalizing(['A1', 'T1'], $get('is_active=1'));
        $this->assertSame(['T1'], $get('area=Teras&is_active=1'));
        $this->assertSame(['T2'], $get('q=T2'));
        $this->assertEqualsCanonicalizing(['T1', 'T2'], $get('q=teras'), 'pencarian juga mencocokkan area');
        $this->assertSame([], $get('q=tidakada'));
    }

    public function test_the_area_list_does_not_shrink_when_filtering_so_the_dropdown_stays_complete(): void
    {
        DiningTable::factory()->inArea('Indoor')->create(['name' => 'A1']);
        DiningTable::factory()->inArea('Teras')->create(['name' => 'T1']);

        $res = $this->getJson('/api/v1/dining-tables?page=1&area=Indoor')->assertOk();

        $this->assertSame(['Indoor', 'Teras'], $res->json('meta.areas'));
    }

    public function test_the_outlet_filter_only_narrows_what_the_user_may_see(): void
    {
        [$a, $b] = $this->makeOutlets(2);
        $this->tableAt($a, 'A1');
        $this->tableAt($b, 'B1');

        $this->assertSame(['A1'], $this->names($this->getJson('/api/v1/dining-tables?page=1&outlet_id[]='.$a->id)->assertOk()));

        $restricted = $this->assignUser(User::factory()->create(['role' => 'inventory']), [$a]);
        $this->actingAs($restricted, 'sanctum');
        $this->assertSame(['A1'], $this->names($this->getJson('/api/v1/dining-tables?page=1')->assertOk()));
        $this->assertSame([], $this->names($this->getJson('/api/v1/dining-tables?page=1&outlet_id[]='.$b->id)->assertOk()), 'outlet di luar hak → kosong, bukan semua');
    }

    public function test_paging_is_stable_and_pages_do_not_overlap(): void
    {
        foreach (range(1, 12) as $n) {
            DiningTable::factory()->create(['name' => sprintf('M%02d', $n), 'display_order' => 0]);
        }

        $one = $this->names($this->getJson('/api/v1/dining-tables?page=1&per_page=5')->assertOk());
        $two = $this->names($this->getJson('/api/v1/dining-tables?page=2&per_page=5')->assertOk());
        $three = $this->names($this->getJson('/api/v1/dining-tables?page=3&per_page=5')->assertOk());

        $this->assertSame([], array_intersect($one, $two));
        $this->assertCount(12, array_unique(array_merge($one, $two, $three)));
        $this->assertSame(array_merge($one, $two, $three), collect(range(1, 12))->map(fn ($n) => sprintf('M%02d', $n))->all());
    }

    public function test_the_number_of_queries_does_not_grow_with_the_page_size(): void
    {
        DiningTable::factory()->count(5)->create();
        DB::enableQueryLog();
        $this->getJson('/api/v1/dining-tables?page=1&per_page=5')->assertOk();
        $small = count(DB::getQueryLog());

        DiningTable::factory()->count(60)->create();
        DB::flushQueryLog();
        $this->getJson('/api/v1/dining-tables?page=1&per_page=50')->assertOk();
        $large = count(DB::getQueryLog());

        $this->assertSame($small, $large, 'tidak boleh N+1 (has_open_tab dimuat lewat withExists)');
    }

    public function test_the_list_still_needs_the_dining_setup_menu(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->getJson('/api/v1/dining-tables?page=1')->assertForbidden();
    }
}
