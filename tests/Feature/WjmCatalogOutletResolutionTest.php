<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\Setting;
use App\Support\ModeGate;
use App\Support\WjmCatalogSeedReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WjmCatalogFixtures;
use Tests\TestCase;

/**
 * 069 (research D8, FR-012, FR-006) — outlet tujuan ditentukan oleh katalog, bukan ditebak. Katalog outlet kedua
 * (`demo_missing`) tidak pernah jatuh ke satu-satunya outlet aktif yang namanya lain: itu akan memasang menu di
 * outlet pertama.
 */
class WjmCatalogOutletResolutionTest extends TestCase
{
    use RefreshDatabase;
    use WjmCatalogFixtures;

    private function second(): string
    {
        return $this->wjmCatalogPath(null, 'wjm2-catalog');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Setting::updateOrCreate(['key' => 'multi_outlet_enabled'], ['value' => 'true', 'type' => 'boolean']);
    }

    private function outlet(string $mode, string $code, string $name): Outlet
    {
        return ModeGate::runAs($mode, fn () => Outlet::factory()->create(['code' => $code, 'name' => $name]));
    }

    public function test_live_refuses_when_the_named_outlet_is_missing_even_if_another_outlet_is_the_only_active_one(): void
    {
        $this->outlet('live', 'WJA', 'Warkop Pertama');
        $before = $this->rowCounts();

        $report = $this->wjmRun(['live'], path: $this->second())['live'];

        $this->assertSame(WjmCatalogSeedReport::REFUSED, $report->status);
        $this->assertStringContainsString('Warkop Uji 2', $report->problems[0]);
        $this->assertStringContainsString('LIVE', $report->problems[0]);
        $this->assertSame($before, $this->rowCounts());
    }

    public function test_live_uses_the_existing_named_outlet_and_creates_none(): void
    {
        $target = $this->outlet('live', 'WJD', 'Warkop Uji 2');

        $report = $this->wjmRun(['live'], path: $this->second())['live'];

        $this->assertSame(WjmCatalogSeedReport::APPLIED, $report->status, implode(' | ', $report->problems));
        $this->assertSame(['code' => 'WJD', 'name' => 'Warkop Uji 2', 'created' => false], $report->outlet);
        $this->assertSame(1, ModeGate::runAs('live', fn () => Outlet::count()));
        $this->assertSame(3, ModeGate::runAs('live', fn () => Product::where('home_outlet_id', $target->id)->count()));
    }

    public function test_demo_creates_the_named_outlet_instead_of_using_the_only_active_one(): void
    {
        $first = $this->outlet('demo', 'TKO', 'Toko Pertama');

        $report = $this->wjmRun(['demo'], path: $this->second())['demo'];

        $this->assertSame(WjmCatalogSeedReport::APPLIED, $report->status, implode(' | ', $report->problems));
        $this->assertSame(['code' => 'WU2', 'name' => 'Warkop Uji 2', 'created' => true], $report->outlet);
        ModeGate::runAs('demo', function () use ($first) {
            $this->assertSame(2, Outlet::count());
            $this->assertSame(0, Product::where('home_outlet_id', $first->id)->count(), 'outlet pertama tidak boleh menerima item');
            $this->assertSame(3, Product::where('home_outlet_id', Outlet::where('code', 'WU2')->value('id'))->count());
        });
    }

    public function test_a_new_outlet_takes_the_next_free_code_when_the_proposed_one_is_taken_in_any_mode(): void
    {
        $this->outlet('live', 'WU2', 'Outlet Lain di LIVE');
        $this->outlet('demo', 'TKO', 'Toko Pertama');

        $report = $this->wjmRun(['demo'], path: $this->second())['demo'];

        $this->assertSame(WjmCatalogSeedReport::APPLIED, $report->status, implode(' | ', $report->problems));
        $this->assertSame('WJA', $report->outlet['code']);
        $this->assertTrue($report->outlet['created']);
    }

    public function test_two_outlets_with_the_catalog_name_are_ambiguous_and_refused_without_any_change(): void
    {
        $this->outlet('live', 'UA1', 'Warkop Uji 2');
        $this->outlet('live', 'UA2', 'warkop uji 2');
        $before = $this->rowCounts();

        $report = $this->wjmRun(['live'], path: $this->second())['live'];

        $this->assertSame(WjmCatalogSeedReport::REFUSED, $report->status);
        $this->assertStringContainsString('--outlet=KODE', $report->problems[0]);
        $this->assertSame($before, $this->rowCounts());
    }

    public function test_an_explicit_outlet_code_overrides_the_catalog_name(): void
    {
        $this->outlet('live', 'XYZ', 'Nama Lain');

        $report = $this->wjmRun(['live'], 'XYZ', path: $this->second())['live'];

        $this->assertSame(WjmCatalogSeedReport::APPLIED, $report->status, implode(' | ', $report->problems));
        $this->assertSame('XYZ', $report->outlet['code']);
    }

    public function test_the_first_outlet_catalog_keeps_the_068_behaviour_of_using_the_only_active_outlet(): void
    {
        $this->outlet('live', 'TKO', 'Toko Utama');

        $report = $this->wjmRun(['live'])['live'];

        $this->assertSame(['code' => 'TKO', 'name' => 'Toko Utama', 'created' => false], $report->outlet);
    }
}
