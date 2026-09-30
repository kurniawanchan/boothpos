<?php

namespace Tests\Feature;

use App\Services\Backup\DatabaseDumper;
use App\Services\BackupService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeDatabaseDumper;
use Tests\TestCase;

/**
 * `php artisan app:backup` / `app:restore` — perilaku yang terlihat operator
 * dipertahankan setelah keduanya dipindah ke BackupService/DatabaseDumper
 * (sumber yang sama dengan antarmuka Pengaturan). Proses mysqldump/mysql diganti
 * FakeDatabaseDumper; folder cadangan diarahkan ke folder sementara.
 */
class BackupCommandsTest extends TestCase
{
    private FakeDatabaseDumper $dumper;

    private string $backupDir;

    private string $externalDir;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir().'/boothpos-cmd-test-'.uniqid();
        $this->backupDir = "{$base}/local";
        $this->externalDir = "{$base}/external";
        File::ensureDirectoryExists($this->externalDir);
        config(['backup.path' => $this->backupDir, 'backup.external_path' => null]);
        Storage::fake('local');
        $this->dumper = new FakeDatabaseDumper;
        $this->app->instance(DatabaseDumper::class, $this->dumper);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->backupDir));
        parent::tearDown();
    }

    // --- app:backup ----------------------------------------------------------

    public function test_backup_command_creates_a_backup_through_the_shared_service(): void
    {
        $this->artisan('app:backup')->assertSuccessful()->expectsOutputToContain('Selesai');

        $backups = app(BackupService::class)->list();
        $this->assertCount(1, $backups);
        $this->assertFileExists("{$this->backupDir}/{$backups[0]['id']}/database.sql");
    }

    public function test_backup_command_fails_with_a_message_and_keeps_nothing_when_the_dump_fails(): void
    {
        $this->dumper->failDump = true;

        $this->artisan('app:backup')->assertFailed()->expectsOutputToContain('Cadangan gagal');

        $this->assertSame([], app(BackupService::class)->list());
    }

    public function test_backup_command_warns_when_no_external_copy_is_configured(): void
    {
        $this->artisan('app:backup')->assertSuccessful()->expectsOutputToContain('BACKUP_EXTERNAL_PATH tidak diset');
    }

    public function test_backup_command_warns_when_the_external_path_does_not_exist(): void
    {
        config(['backup.external_path' => "{$this->externalDir}/hilang"]);

        $this->artisan('app:backup')->assertSuccessful()->expectsOutputToContain('BACKUP_EXTERNAL_PATH tidak diset atau tidak ditemukan');
    }

    public function test_backup_command_copies_to_the_external_path_when_configured(): void
    {
        config(['backup.external_path' => $this->externalDir]);

        $this->artisan('app:backup')->assertSuccessful()->expectsOutputToContain('Menyalin ke penyimpanan eksternal');

        $id = app(BackupService::class)->list()[0]['id'];
        $this->assertFileExists("{$this->externalDir}/{$id}/database.sql");
    }

    // --- app:restore ---------------------------------------------------------

    public function test_restore_command_fails_when_the_file_is_missing(): void
    {
        $this->artisan('app:restore', ['path' => "{$this->backupDir}/tidak-ada.sql", '--force' => true])
            ->assertFailed()->expectsOutputToContain('Berkas tidak ditemukan');

        $this->assertSame([], $this->dumper->calls);
    }

    public function test_restore_command_with_force_loads_the_file_through_the_dumper(): void
    {
        File::ensureDirectoryExists($this->backupDir);
        $file = "{$this->backupDir}/manual.sql";
        file_put_contents($file, FakeDatabaseDumper::VALID_DUMP);

        $this->artisan('app:restore', ['path' => $file, '--force' => true])
            ->assertSuccessful()->expectsOutputToContain('berhasil dipulihkan');

        $this->assertSame([['restore', $file]], $this->dumper->calls);
        $this->assertSame(FakeDatabaseDumper::VALID_DUMP, $this->dumper->restoredContent);
    }

    public function test_restore_command_asks_for_confirmation_and_stops_when_declined(): void
    {
        File::ensureDirectoryExists($this->backupDir);
        $file = "{$this->backupDir}/manual.sql";
        file_put_contents($file, FakeDatabaseDumper::VALID_DUMP);

        $this->artisan('app:restore', ['path' => $file])
            ->expectsConfirmation("Ini akan MENIMPA SELURUH DATA di database '".config('database.connections.mysql.database')."' dengan isi {$file}. Lanjutkan?", 'no')
            ->assertFailed();

        $this->assertSame([], $this->dumper->calls);
    }

    public function test_restore_command_reports_a_failed_restore(): void
    {
        File::ensureDirectoryExists($this->backupDir);
        $file = "{$this->backupDir}/manual.sql";
        file_put_contents($file, FakeDatabaseDumper::VALID_DUMP);
        $this->dumper->failRestore = true;

        $this->artisan('app:restore', ['path' => $file, '--force' => true])
            ->assertFailed()->expectsOutputToContain('Pemulihan gagal');
    }
}
