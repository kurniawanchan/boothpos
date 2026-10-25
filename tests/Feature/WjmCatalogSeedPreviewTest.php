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
 * 068 US4 (FR-022, research D9) — pratinjau menjalankan jalur yang sama lalu membatalkannya: laporannya sama
 * dengan jalan sungguhan, tetapi tidak ada baris maupun berkas yang tersimpan; boleh pada katalog draf.
 */
class WjmCatalogSeedPreviewTest extends TestCase
{
    use RefreshDatabase;
    use WjmCatalogFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function draftPath(): string
    {
        return $this->wjmCatalogPath(fn (array $d) => array_replace($d, ['approval' => ['status' => 'draft', 'approved_on' => null, 'approved_by_role' => null]]));
    }

    public function test_a_preview_reports_what_a_real_run_does_but_saves_no_rows_and_no_files(): void
    {
        $before = $this->rowCounts();

        $preview = $this->wjmRun(['live'], dryRun: true)['live'];

        $this->assertSame(WjmCatalogSeedReport::PREVIEWED, $preview->status, implode(' | ', $preview->problems));
        $this->assertSame(['code' => 'WJ3', 'name' => 'Warkop WJM 3', 'created' => true], $preview->outlet);
        $this->assertSame($before, $this->rowCounts());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertStringContainsString('7 akan dibuat', $preview->sourceAccounting[0]);

        $real = $this->wjmRun(['live'])['live'];
        $this->assertSame(
            array_map(fn (array $c) => $c['created'], $real->counts),
            array_map(fn (array $c) => $c['created'], $preview->counts),
            'pratinjau harus sama dengan jalan sungguhan',
        );
    }

    public function test_a_draft_catalog_can_be_previewed_but_not_applied(): void
    {
        $preview = $this->wjmRun(['live'], dryRun: true, path: $this->draftPath())['live'];

        $this->assertSame(WjmCatalogSeedReport::PREVIEWED, $preview->status);
        $this->assertStringContainsString('DRAF', $preview->warnings[0]);
        $this->assertSame(WjmCatalogSeedReport::REFUSED, $this->wjmRun(['live'], path: $this->draftPath())['live']->status);
        $this->assertSame(0, Product::withoutGlobalScopes()->count());
    }

    public function test_the_command_preview_prints_the_banner_and_exits_zero(): void
    {
        $this->artisan('app:seed-wjm-catalog', ['--mode' => 'live', '--dry-run' => true, '--catalog' => $this->wjmCatalogPath()])
            ->expectsOutputToContain('PRATINJAU — tidak ada yang disimpan')
            ->assertExitCode(0);

        $this->assertSame(0, Product::withoutGlobalScopes()->count());
    }

    public function test_a_preview_in_production_does_not_ask_for_confirmation(): void
    {
        $this->app['env'] = 'production';

        // Pertanyaan yang tidak diharapkan menggagalkan tes; jadi kode keluar 0 di sini berarti tidak ada konfirmasi.
        $this->artisan('app:seed-wjm-catalog', ['--mode' => 'live', '--dry-run' => true, '--catalog' => $this->wjmCatalogPath()])
            ->assertExitCode(0);

        $this->assertSame(0, Product::withoutGlobalScopes()->count());
    }

    /** 069: outlet pertama terisi di kedua mode; LIVE sudah punya outlet kedua kosong, DEMO belum. */
    private function prepareSecondOutlet(): void
    {
        Setting::updateOrCreate(['key' => 'multi_outlet_enabled'], ['value' => 'true', 'type' => 'boolean']);
        $this->wjmRun(['live', 'demo']);
        ModeGate::runAs('live', fn () => Outlet::factory()->create(['code' => 'WJD', 'name' => 'Warkop Uji 2']));
    }

    public function test_a_preview_of_the_second_catalog_reports_stock_stations_and_withdrawals_and_saves_nothing(): void
    {
        $this->prepareSecondOutlet();
        $before = $this->rowCounts();
        $files = Storage::disk('public')->allFiles();
        $second = $this->wjmCatalogPath(null, 'wjm2-catalog');

        $preview = $this->wjmRun(['live', 'demo'], dryRun: true, path: $second);

        foreach (['live', 'demo'] as $mode) {
            $this->assertSame(WjmCatalogSeedReport::PREVIEWED, $preview[$mode]->status, implode(' | ', $preview[$mode]->problems));
            $this->assertSame(5, $preview[$mode]->counts['stock']['created'], "{$mode}: stok awal per varian baru");
            $this->assertSame(2, $preview[$mode]->counts['stations']['created'], $mode);
            $this->assertCount(7, $preview[$mode]->withdrawn, "{$mode}: item outlet pertama yang akan ditarik");
            $this->assertTrue(collect($preview[$mode]->excluded)->contains(fn (string $e) => str_contains($e, 'MINUMAN KOSONG')), $mode);
            $this->assertStringContainsString('akan dibuat', $preview[$mode]->sourceAccounting[0]);
        }
        $this->assertTrue($preview['demo']->outlet['created'], 'DEMO akan membuat outlet kedua');
        $this->assertFalse($preview['live']->outlet['created']);
        $this->assertSame($before, $this->rowCounts(), 'pratinjau tidak menyimpan baris apa pun');
        $this->assertSame($files, Storage::disk('public')->allFiles(), 'pratinjau tidak menulis foto');

        $real = $this->wjmRun(['live', 'demo'], path: $second);
        foreach (['live', 'demo'] as $mode) {
            $this->assertSame(
                array_map(fn (array $c) => $c['created'], $real[$mode]->counts),
                array_map(fn (array $c) => $c['created'], $preview[$mode]->counts),
                "{$mode}: pratinjau harus sama dengan jalan sungguhan",
            );
        }
    }

    public function test_a_draft_second_catalog_with_placeholder_variant_names_can_be_previewed_but_not_applied(): void
    {
        $this->prepareSecondOutlet();
        $draft = $this->wjmCatalogPath(function (array $d) {
            $d['approval'] = ['status' => 'draft', 'approved_on' => null, 'approved_by_role' => null];
            $d['attributes'][1]['values'][1] = '(belum diisi 2)';
            $d['items'][2]['variants'][1]['name'] = '(belum diisi 2)';
            $d['items'][2]['variants'][1]['values'] = ['Buah Uji' => '(belum diisi 2)'];

            return $d;
        }, 'wjm2-catalog');
        $before = $this->rowCounts();

        $preview = $this->wjmRun(['live'], dryRun: true, path: $draft)['live'];

        $this->assertSame(WjmCatalogSeedReport::PREVIEWED, $preview->status, implode(' | ', $preview->problems));
        $this->assertSame(['Jus Uji'], $preview->placeholderVariants);
        $this->assertSame(['Suhu (2 nilai)', 'Buah Uji (2 nilai)'], $preview->mappedAttributes);
        $this->assertSame(WjmCatalogSeedReport::REFUSED, $this->wjmRun(['live'], path: $draft)['live']->status);
        $this->assertSame($before, $this->rowCounts());
    }
}
