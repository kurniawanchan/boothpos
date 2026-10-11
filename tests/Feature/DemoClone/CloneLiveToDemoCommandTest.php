<?php

namespace Tests\Feature\DemoClone;

use App\Console\Commands\CloneLiveToDemo;
use App\Services\Backup\DatabaseDumper;
use App\Services\DemoClone\CloneRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCloneFixtures;
use Tests\Support\FakeDatabaseDumper;
use Tests\TestCase;

class CloneLiveToDemoCommandTest extends TestCase
{
    use BuildsCloneFixtures, RefreshDatabase;

    private FakeDatabaseDumper $dumper;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDir = sys_get_temp_dir().'/boothpos-clone-test-'.uniqid();
        config(['backup.path' => $this->backupDir, 'backup.external_path' => null]);
        Storage::fake('public');
        Storage::fake('local');
        $this->dumper = new FakeDatabaseDumper;
        $this->app->instance(DatabaseDumper::class, $this->dumper);
        $this->buildGraph('live');
        $this->buildGraph('demo');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupDir);
        parent::tearDown();
    }

    private function demoUntouched(): bool
    {
        return DB::table('artists')->where('code', 'DMO')->exists();
    }

    public function test_default_is_a_dry_run_that_writes_nothing(): void
    {
        $this->artisan('app:clone-live-to-demo')
            ->expectsOutputToContain('Simulasi')
            ->assertSuccessful();

        $this->assertTrue($this->demoUntouched());
        $this->assertSame([], $this->dumper->calls);
    }

    public function test_declining_the_confirmation_aborts_without_a_backup(): void
    {
        $this->artisan('app:clone-live-to-demo --force')
            ->expectsConfirmation(CloneLiveToDemo::CONFIRM_QUESTION, 'no')
            ->assertFailed();

        $this->assertTrue($this->demoUntouched());
        $this->assertSame([], $this->dumper->calls);
    }

    public function test_a_non_interactive_force_without_yes_aborts(): void
    {
        $this->artisan('app:clone-live-to-demo --force --no-interaction')->assertFailed();

        $this->assertTrue($this->demoUntouched());
    }

    public function test_force_and_yes_take_a_backup_first_then_clone(): void
    {
        $this->artisan('app:clone-live-to-demo --force --yes')
            ->expectsOutputToContain('Cadangan pengaman')
            ->assertSuccessful();

        $this->assertSame('dump', $this->dumper->calls[0][0]);
        $this->assertFalse($this->demoUntouched());
        $this->assertSame('ZLI', DB::table('artists')->where('data_mode', 'demo')->value('code'));
        $this->assertSame('LIV', DB::table('artists')->where('data_mode', 'live')->value('code'));
    }

    public function test_a_failed_backup_aborts_before_anything_is_written(): void
    {
        $this->dumper->failDump = true;

        $this->artisan('app:clone-live-to-demo --force --yes')
            ->expectsOutputToContain('Cadangan pengaman gagal')
            ->assertFailed();

        $this->assertTrue($this->demoUntouched());
    }

    /** Kosongkan data LIVE: lepas self-FK, lalu hapus baris LIVE dari anak ke induk. */
    private function emptyLive(): void
    {
        $tables = array_reverse(array_keys(CloneRegistry::tables()));

        foreach ($tables as $table) {
            foreach (CloneRegistry::spec($table)['deferred'] as $column => $_) {
                DB::table($table)->whereRaw(CloneRegistry::scope($table, 'live'))->update([$column => null]);
            }
        }

        foreach ($tables as $table) {
            DB::table($table)->whereRaw(CloneRegistry::scope($table, 'live'))->delete();
        }
    }

    public function test_it_refuses_when_live_has_nothing_to_clone(): void
    {
        $this->emptyLive();

        $this->artisan('app:clone-live-to-demo --force --yes')
            ->expectsOutputToContain('Tidak ada data LIVE')
            ->assertFailed();

        $this->assertTrue($this->demoUntouched());
        $this->assertSame([], $this->dumper->calls);
    }
}
