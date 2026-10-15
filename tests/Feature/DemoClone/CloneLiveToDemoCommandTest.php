<?php

namespace Tests\Feature\DemoClone;

use App\Console\Commands\CloneLiveToDemo;
use App\Services\Backup\DatabaseDumper;
use App\Services\DemoClone\CloneRegistry;
use App\Services\DemoClone\LiveFingerprint;
use App\Services\DemoClone\LiveToDemoCloner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCloneFixtures;
use PDOException;
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

    public function test_the_report_shows_the_artist_and_category_code_mapping(): void
    {
        // Kode artis/kategori DEMO diganti (kolomnya terlalu sempit untuk akhiran);
        // pengguna harus bisa melihat padanannya LIVE → DEMO.
        $this->artisan('app:clone-live-to-demo --force --yes')
            ->expectsOutputToContain('LIV → ZLI')
            ->expectsOutputToContain('LC → ZL')
            ->assertSuccessful();
    }

    public function test_a_cleanup_failure_after_commit_is_reported_as_a_warning_not_a_rollback(): void
    {
        $this->app->instance(LiveToDemoCloner::class, new class extends LiveToDemoCloner
        {
            protected function deleteUnreferencedFiles(array $stale): void
            {
                throw new \RuntimeException('disk penuh');
            }
        });

        $this->artisan('app:clone-live-to-demo --force --yes')
            ->expectsOutputToContain('pembersihan berkas DEMO lama gagal')
            ->doesntExpectOutputToContain('dibatalkan')
            ->assertSuccessful();

        $this->assertFalse($this->demoUntouched(), 'DEMO sudah diganti');
    }

    public function test_a_database_failure_never_prints_or_logs_a_pii_value(): void
    {
        // Spec §9: keluaran galat tidak boleh memuat nilai baris pelanggan.
        $pdo = new PDOException("SQLSTATE[23000]: Duplicate entry 'SENTINEL-PHONE-999' for key 'customers.x'");
        $pdo->errorInfo = ['23000', 1062, "Duplicate entry 'SENTINEL-PHONE-999'"];

        $this->app->instance(LiveToDemoCloner::class, new class($pdo) extends LiveToDemoCloner
        {
            public function __construct(private PDOException $pdo) {}

            public function run(): array
            {
                throw new QueryException('mysql', 'insert into `customers` (`phone`) values (?)', ['SENTINEL-PHONE-999'], $this->pdo);
            }
        });

        Log::spy();
        $before = LiveFingerprint::capture();

        $this->artisan('app:clone-live-to-demo --force --yes')
            ->expectsOutputToContain('Gagal')
            ->doesntExpectOutputToContain('SENTINEL-PHONE-999')
            ->assertFailed();

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn ($message) => str_contains($message, 'customers') && ! str_contains($message, 'SENTINEL'),
        );
        $this->assertTrue($this->demoUntouched());
        $this->assertSame([], LiveFingerprint::diff($before, LiveFingerprint::capture()));
    }
}
