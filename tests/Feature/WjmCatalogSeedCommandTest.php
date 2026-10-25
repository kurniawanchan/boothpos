<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WjmCatalogFixtures;
use Tests\TestCase;

/**
 * 068 US1 (contracts/cli.md §1) — opsi, konfirmasi produksi, dan kode keluar perintah app:seed-wjm-catalog.
 */
class WjmCatalogSeedCommandTest extends TestCase
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

    public function test_it_seeds_live_from_the_given_catalog_and_exits_zero(): void
    {
        $this->artisan('app:seed-wjm-catalog', ['--mode' => 'live', '--catalog' => $this->wjmCatalogPath()])
            // Mockery berhenti di ekspektasi pertama yang cocok, jadi tiap teks harus dari baris yang berbeda.
            ->expectsOutputToContain('Mode LIVE')
            ->expectsOutputToContain('Selesai: LIVE applied')
            ->assertExitCode(0);

        $this->assertSame(7, ModeGate::runAs('live', fn () => Product::count()));
    }

    public function test_without_a_catalog_option_it_reads_the_configured_path(): void
    {
        config(['seeding.wjm_catalog_path' => $this->wjmCatalogPath()]);

        $this->artisan('app:seed-wjm-catalog', ['--mode' => 'live'])->assertExitCode(0);

        $this->assertSame(7, ModeGate::runAs('live', fn () => Product::count()));
    }

    public function test_invalid_options_exit_one_without_any_change(): void
    {
        $before = $this->rowCounts();

        $this->artisan('app:seed-wjm-catalog', ['--mode' => 'both', '--outlet' => 'XYZ', '--catalog' => $this->wjmCatalogPath()])->assertExitCode(1);
        $this->artisan('app:seed-wjm-catalog', ['--mode' => 'foo', '--catalog' => $this->wjmCatalogPath()])->assertExitCode(1);

        $this->assertSame($before, $this->rowCounts());
    }

    public function test_a_draft_catalog_exits_one_and_says_it_is_a_draft(): void
    {
        $this->artisan('app:seed-wjm-catalog', ['--mode' => 'live', '--catalog' => $this->draftPath()])
            ->expectsOutputToContain('DRAF')
            ->assertExitCode(1);

        $this->assertSame(0, Product::withoutGlobalScopes()->count());
    }

    public function test_production_asks_for_confirmation_unless_forced(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('app:seed-wjm-catalog', ['--mode' => 'live', '--catalog' => $this->wjmCatalogPath()])
            ->expectsConfirmation('Are you sure you want to run this command?', 'no')
            ->assertExitCode(1);
        $this->assertSame(0, Product::withoutGlobalScopes()->count());

        $this->artisan('app:seed-wjm-catalog', ['--mode' => 'live', '--force' => true, '--catalog' => $this->wjmCatalogPath()])
            ->assertExitCode(0);
        $this->assertSame(7, Product::withoutGlobalScopes()->count());
    }
}
