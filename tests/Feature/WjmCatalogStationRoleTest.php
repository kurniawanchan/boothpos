<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Station;
use App\Support\ModeGate;
use App\Support\WjmCatalogSeedReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WjmCatalogFixtures;
use Tests\TestCase;

/**
 * 069 (research D6, FR-008) — stasiun dikenali menurut PERAN (Bar/Dapur) lewat daftar nama dari katalog, bukan nama
 * tetap. Stasiun LIVE WJM 3 sudah diganti nama menjadi "Dapur Minuman"/"Dapur Makanan"; mencocokkan nama tetap
 * akan menggandakan stasiun pada jalan ulang.
 */
class WjmCatalogStationRoleTest extends TestCase
{
    use RefreshDatabase;
    use WjmCatalogFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /** Katalog outlet kedua dengan nama stasiun gaya LIVE WJM 3. */
    private function aliased(): string
    {
        return $this->wjmCatalogPath(function (array $d) {
            $d['stations'] = ['Bar' => ['Dapur Minuman', 'Bar'], 'Dapur' => ['Dapur Makanan', 'Dapur']];

            return $d;
        }, 'wjm2-catalog');
    }

    private function target(): Outlet
    {
        return ModeGate::runAs('live', fn () => Outlet::factory()->create(['code' => 'WJD', 'name' => 'Warkop Uji 2']));
    }

    private function station(Outlet $outlet, string $name): Station
    {
        return ModeGate::runAs('live', function () use ($outlet, $name) {
            $station = new Station(['name' => $name]);
            $station->outlet_id = $outlet->id;
            $station->save();

            return $station;
        });
    }

    /** @return list<string> */
    private function stationNames(Outlet $outlet): array
    {
        return ModeGate::runAs('live', fn () => Station::withoutGlobalScopes()->where('outlet_id', $outlet->id)->orderBy('id')->pluck('name')->all());
    }

    public function test_an_outlet_without_stations_gets_one_per_role_named_after_the_first_catalog_name(): void
    {
        $outlet = $this->target();

        $report = $this->wjmRun(['live'], path: $this->aliased())['live'];

        $this->assertSame(WjmCatalogSeedReport::APPLIED, $report->status, implode(' | ', $report->problems));
        $this->assertSame(['Dapur Minuman', 'Dapur Makanan'], $this->stationNames($outlet));
        $this->assertSame(2, $report->counts['stations']['created']);
    }

    public function test_renamed_stations_are_recognised_by_role_and_not_duplicated(): void
    {
        $outlet = $this->target();
        $this->station($outlet, 'Dapur Minuman');
        $this->station($outlet, 'Dapur Makanan');

        $report = $this->wjmRun(['live'], path: $this->aliased())['live'];

        $this->assertSame(WjmCatalogSeedReport::APPLIED, $report->status, implode(' | ', $report->problems));
        $this->assertSame(['Dapur Minuman', 'Dapur Makanan'], $this->stationNames($outlet));
        $this->assertSame([0, 2], [$report->counts['stations']['created'], $report->counts['stations']['existing']]);
    }

    public function test_default_station_names_are_recognised_through_the_same_lists(): void
    {
        $outlet = $this->target();
        $this->station($outlet, 'Bar');
        $this->station($outlet, 'Dapur');

        $report = $this->wjmRun(['live'], path: $this->aliased())['live'];

        $this->assertSame(WjmCatalogSeedReport::APPLIED, $report->status, implode(' | ', $report->problems));
        $this->assertSame(['Bar', 'Dapur'], $this->stationNames($outlet));
    }

    public function test_stations_the_catalog_does_not_recognise_refuse_the_mode_without_any_change(): void
    {
        $outlet = $this->target();
        $this->station($outlet, 'Kasir Depan');
        $before = $this->rowCounts();

        $report = $this->wjmRun(['live'], path: $this->aliased())['live'];

        $this->assertSame(WjmCatalogSeedReport::REFUSED, $report->status);
        $this->assertStringContainsString('Bar', $report->problems[0]);
        $this->assertStringContainsString('Kasir Depan', $report->problems[0]);
        $this->assertSame($before, $this->rowCounts());
        $this->assertSame(['Kasir Depan'], $this->stationNames($outlet));
    }

    public function test_a_role_without_a_matching_station_refuses_even_when_the_other_role_matches(): void
    {
        $outlet = $this->target();
        $this->station($outlet, 'Bar');
        $before = $this->rowCounts();

        $report = $this->wjmRun(['live'], path: $this->aliased())['live'];

        $this->assertSame(WjmCatalogSeedReport::REFUSED, $report->status);
        $this->assertStringContainsString('Dapur', $report->problems[0]);
        $this->assertSame($before, $this->rowCounts());
    }

    public function test_a_second_run_adds_no_stations(): void
    {
        $outlet = $this->target();
        $path = $this->aliased();

        $this->wjmRun(['live'], path: $path);
        $second = $this->wjmRun(['live'], path: $path)['live'];

        $this->assertSame(WjmCatalogSeedReport::APPLIED, $second->status, implode(' | ', $second->problems));
        $this->assertSame(['Dapur Minuman', 'Dapur Makanan'], $this->stationNames($outlet));
        $this->assertSame(0, $second->counts['stations']['created']);
    }
}
