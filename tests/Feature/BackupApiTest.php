<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Backup\DatabaseDumper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeDatabaseDumper;
use Tests\TestCase;

/**
 * Antarmuka cadangan & pemulihan database (Pengaturan). Proses mysqldump/mysql
 * digantikan FakeDatabaseDumper (lihat DatabaseDumper: tidak tersedia di image
 * dev, dan restore sungguhan akan menimpa database); direktori cadangan diarahkan
 * ke folder sementara supaya test tak menyentuh cadangan asli di storage/app.
 */
class BackupApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeDatabaseDumper $dumper;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDir = sys_get_temp_dir().'/boothpos-backup-test-'.uniqid();
        config(['backup.path' => $this->backupDir, 'backup.external_path' => null]);
        Storage::fake('local'); // jangan pernah mengarsipkan bukti pembayaran asli di mesin dev
        $this->dumper = new FakeDatabaseDumper;
        $this->app->instance(DatabaseDumper::class, $this->dumper);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupDir);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function createBackup(): array
    {
        return $this->postJson('/api/v1/backups')->assertCreated()->json();
    }

    // --- akses ---------------------------------------------------------------

    public function test_a_role_without_owner_or_admin_rights_is_refused_everywhere(): void
    {
        $this->actingAsRole('owner');
        $backup = $this->createBackup();
        $this->actingAsRole('cashier');

        $this->getJson('/api/v1/backups')->assertStatus(403);
        $this->postJson('/api/v1/backups')->assertStatus(403);
        $this->getJson("/api/v1/backups/{$backup['id']}/download")->assertStatus(403);
        $this->postJson("/api/v1/backups/{$backup['id']}/restore", ['confirm' => 'RESTORE'])->assertStatus(403);
        $this->deleteJson("/api/v1/backups/{$backup['id']}")->assertStatus(403);
        $this->assertDirectoryExists("{$this->backupDir}/{$backup['id']}"); // masih ada
        $this->postJson('/api/v1/backups/restore-upload', [
            'file' => UploadedFile::fake()->createWithContent('b.sql', FakeDatabaseDumper::VALID_DUMP), 'confirm' => 'RESTORE',
        ])->assertStatus(403);

        $this->assertNotContains('restore', $this->dumper->callTypes());
    }

    // --- buat & daftar -------------------------------------------------------

    public function test_an_empty_installation_lists_no_backups(): void
    {
        $this->actingAsRole('owner');

        $this->getJson('/api/v1/backups')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_owner_can_create_a_backup_and_it_appears_in_the_list(): void
    {
        $this->actingAsRole('owner');

        $created = $this->postJson('/api/v1/backups')->assertCreated()
            ->assertJsonPath('has_payment_proofs', false)->json();

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}_\d{6}(-\d+)?$/', $created['id']);
        $this->assertSame(strlen(FakeDatabaseDumper::VALID_DUMP), $created['size_bytes']);
        $this->assertNotEmpty($created['created_at']);
        $this->assertFileExists("{$this->backupDir}/{$created['id']}/database.sql");

        $this->getJson('/api/v1/backups')->assertOk()->assertJsonPath('data.0.id', $created['id']);
    }

    public function test_admin_can_also_create_backups(): void
    {
        $this->actingAsRole('admin');

        $this->postJson('/api/v1/backups')->assertCreated();
    }

    public function test_backups_are_listed_newest_first_and_two_in_the_same_second_get_distinct_ids(): void
    {
        $this->actingAsRole('owner');
        Carbon::setTestNow('2026-09-30 10:00:00');
        $first = $this->createBackup();
        $second = $this->createBackup(); // detik yang sama
        Carbon::setTestNow('2026-09-30 10:00:05');
        $third = $this->createBackup();

        $this->assertCount(3, array_unique([$first['id'], $second['id'], $third['id']]));
        $ids = collect($this->getJson('/api/v1/backups')->json('data'))->pluck('id')->all();
        $this->assertSame($third['id'], $ids[0]);
        $this->assertEqualsCanonicalizing([$first['id'], $second['id'], $third['id']], $ids);
    }

    public function test_a_backup_includes_payment_proofs_when_they_exist(): void
    {
        $this->actingAsRole('owner');
        Storage::disk('local')->put('payment-proofs/bukti-1.jpg', 'fake-image-bytes');

        $created = $this->createBackup();

        $this->assertTrue($created['has_payment_proofs']);
        $this->assertFileExists("{$this->backupDir}/{$created['id']}/payment-proofs.tar.gz");
    }

    public function test_a_failed_dump_returns_an_error_and_leaves_no_half_made_backup(): void
    {
        $this->actingAsRole('owner');
        $this->dumper->failDump = true;

        $this->postJson('/api/v1/backups')->assertStatus(500)->assertJsonStructure(['message']);

        $this->getJson('/api/v1/backups')->assertJsonPath('data', []);
        $this->assertSame([], glob("{$this->backupDir}/*") ?: []);
    }

    // --- unduh ---------------------------------------------------------------

    public function test_owner_can_download_a_backups_sql_file(): void
    {
        $this->actingAsRole('owner');
        $backup = $this->createBackup();

        $response = $this->get("/api/v1/backups/{$backup['id']}/download");

        $response->assertOk();
        $this->assertStringContainsString($backup['id'], $response->headers->get('content-disposition'));
        $this->assertSame(FakeDatabaseDumper::VALID_DUMP, $response->streamedContent());
    }

    public function test_downloading_an_unknown_or_malformed_id_is_a_404(): void
    {
        $this->actingAsRole('owner');
        // Id yang SAH harus bisa diunduh — kalau tidak, 404 di bawah bisa saja
        // datang dari rute yang belum ada, bukan dari validasi id.
        $known = $this->createBackup();
        $this->get("/api/v1/backups/{$known['id']}/download")->assertOk();

        $this->getJson('/api/v1/backups/2020-01-01_000000/download')->assertStatus(404);
        $this->getJson('/api/v1/backups/..%2F..%2F.env/download')->assertStatus(404);
        $this->getJson('/api/v1/backups/not-an-id/download')->assertStatus(404);
    }

    // --- pulihkan dari cadangan ---------------------------------------------

    public function test_restore_requires_the_confirmation_word_and_touches_nothing_without_it(): void
    {
        $this->actingAsRole('owner');
        $backup = $this->createBackup();
        $this->dumper->calls = [];

        $this->postJson("/api/v1/backups/{$backup['id']}/restore")->assertStatus(422);
        $this->postJson("/api/v1/backups/{$backup['id']}/restore", ['confirm' => 'restore'])->assertStatus(422);
        $this->postJson("/api/v1/backups/{$backup['id']}/restore", ['confirm' => 'yes'])->assertStatus(422);

        $this->assertSame([], $this->dumper->calls);
    }

    public function test_restore_takes_a_safety_backup_first_then_loads_the_chosen_one(): void
    {
        $this->actingAsRole('owner');
        Log::spy();
        $backup = $this->createBackup();
        $this->dumper->calls = [];
        $this->dumper->dumpContent = "-- state right before restore\n".FakeDatabaseDumper::VALID_DUMP;

        $response = $this->postJson("/api/v1/backups/{$backup['id']}/restore", ['confirm' => 'RESTORE'])
            ->assertOk()->assertJsonPath('source', $backup['id']);

        // Urutan wajib: cadangan pengaman DULU, baru menimpa database.
        $this->assertSame(['dump', 'restore'], $this->dumper->callTypes());
        $this->assertSame(FakeDatabaseDumper::VALID_DUMP, $this->dumper->restoredContent);

        $safetyId = $response->json('safety_backup.id');
        $this->assertNotSame($backup['id'], $safetyId);
        $this->assertStringContainsString('state right before restore', file_get_contents("{$this->backupDir}/{$safetyId}/database.sql"));
        $this->assertContains($safetyId, collect($this->getJson('/api/v1/backups')->json('data'))->pluck('id')->all());

        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'restore'))->once();
    }

    public function test_restoring_an_unknown_backup_is_a_404_and_touches_nothing(): void
    {
        $this->actingAsRole('owner');
        $known = $this->createBackup(); // pastikan rute ada: id sah tidak boleh 404
        $this->dumper->calls = [];

        $this->postJson('/api/v1/backups/2020-01-01_000000/restore', ['confirm' => 'RESTORE'])->assertStatus(404);
        $this->assertSame([], $this->dumper->calls);

        $this->postJson("/api/v1/backups/{$known['id']}/restore", ['confirm' => 'RESTORE'])->assertOk();
    }

    public function test_if_the_safety_backup_fails_nothing_is_restored(): void
    {
        $this->actingAsRole('owner');
        $backup = $this->createBackup();
        $this->dumper->calls = [];
        $this->dumper->failDump = true;

        // Pesan harus menjelaskan bahwa pemulihan DIBATALKAN (bukan cuma galat mentah dari mysqldump).
        $this->postJson("/api/v1/backups/{$backup['id']}/restore", ['confirm' => 'RESTORE'])
            ->assertStatus(500)
            ->assertJsonPath('message', __('backups.safety_backup_failed', ['reason' => 'mysqldump gagal (fake)']));

        $this->assertNotContains('restore', $this->dumper->callTypes());
    }

    public function test_a_failed_restore_reports_an_error_and_keeps_the_safety_backup(): void
    {
        $this->actingAsRole('owner');
        $backup = $this->createBackup();
        $this->dumper->failRestore = true;

        $this->postJson("/api/v1/backups/{$backup['id']}/restore", ['confirm' => 'RESTORE'])
            ->assertStatus(500)->assertJsonStructure(['message']);

        $this->assertCount(2, $this->getJson('/api/v1/backups')->json('data')); // asli + pengaman
    }

    // --- pulihkan dari berkas unggahan --------------------------------------

    public function test_owner_can_restore_from_an_uploaded_sql_file(): void
    {
        $this->actingAsRole('owner');
        $sql = "-- dari laptop lain\n".FakeDatabaseDumper::VALID_DUMP;

        $response = $this->postJson('/api/v1/backups/restore-upload', [
            'file' => UploadedFile::fake()->createWithContent('cadangan.sql', $sql), 'confirm' => 'RESTORE',
        ])->assertOk()->assertJsonPath('source', 'upload');

        $this->assertSame(['dump', 'restore'], $this->dumper->callTypes()); // pengaman dulu
        $this->assertSame($sql, $this->dumper->restoredContent);
        $this->assertNotEmpty($response->json('safety_backup.id'));
    }

    public function test_an_uploaded_file_that_is_not_a_boothpos_dump_is_rejected_before_anything_happens(): void
    {
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/backups/restore-upload', [
            'file' => UploadedFile::fake()->createWithContent('bukan.sql', "DROP DATABASE prod;\n"), 'confirm' => 'RESTORE',
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertSame([], $this->dumper->calls);
    }

    public function test_an_upload_with_the_wrong_extension_or_no_confirmation_is_rejected(): void
    {
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/backups/restore-upload', [
            'file' => UploadedFile::fake()->createWithContent('cadangan.txt', FakeDatabaseDumper::VALID_DUMP), 'confirm' => 'RESTORE',
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->postJson('/api/v1/backups/restore-upload', [
            'file' => UploadedFile::fake()->createWithContent('cadangan.sql', FakeDatabaseDumper::VALID_DUMP),
        ])->assertStatus(422)->assertJsonValidationErrors('confirm');

        $this->assertSame([], $this->dumper->calls);
    }

    public function test_an_upload_php_refused_as_too_large_gets_a_clear_message_not_a_generic_one(): void
    {
        $this->actingAsRole('owner');
        $tmp = tempnam(sys_get_temp_dir(), 'dump');
        file_put_contents($tmp, FakeDatabaseDumper::VALID_DUMP);

        // Yang dikirim PHP bila berkas melewati upload_max_filesize.
        $tooLarge = new UploadedFile($tmp, 'besar.sql', 'application/sql', UPLOAD_ERR_INI_SIZE, true);

        $this->postJson('/api/v1/backups/restore-upload', ['file' => $tooLarge, 'confirm' => 'RESTORE'])
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', __('backups.upload_failed'));

        $this->assertSame([], $this->dumper->calls);
        @unlink($tmp);
    }

    public function test_both_docker_images_let_php_accept_the_50_mb_upload_the_screen_promises(): void
    {
        // max:51200 (KB) di BackupController tak berarti apa-apa bila PHP sendiri
        // menolak berkas lebih dulu — image store dulu tak punya ini sama sekali
        // (default PHP 2M) dan image dev membatasi 10M.
        $ini = parse_ini_file(base_path('docker/php/uploads.ini'));
        $toBytes = fn (string $v) => (int) $v * ['K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3][strtoupper(substr($v, -1))];

        $this->assertGreaterThanOrEqual(50 * 1024 ** 2, $toBytes($ini['upload_max_filesize']));
        $this->assertGreaterThanOrEqual(50 * 1024 ** 2, $toBytes($ini['post_max_size']));

        foreach (['docker/php/Dockerfile', 'docker/store/Dockerfile'] as $dockerfile) {
            $this->assertStringContainsString(
                'docker/php/uploads.ini /usr/local/etc/php/conf.d/',
                file_get_contents(base_path($dockerfile)),
                "{$dockerfile} harus memasang uploads.ini",
            );
        }
    }

    // --- hapus ---------------------------------------------------------------

    public function test_owner_can_delete_a_backup_and_only_that_one_disappears(): void
    {
        $this->actingAsRole('owner');
        Carbon::setTestNow('2026-09-30 10:00:00');
        $keep = $this->createBackup();
        Carbon::setTestNow('2026-09-30 10:00:05');
        $remove = $this->createBackup();
        $this->dumper->calls = [];

        $this->deleteJson("/api/v1/backups/{$remove['id']}")->assertNoContent();

        $this->assertDirectoryDoesNotExist("{$this->backupDir}/{$remove['id']}");
        $this->assertFileExists("{$this->backupDir}/{$keep['id']}/database.sql");
        $ids = collect($this->getJson('/api/v1/backups')->json('data'))->pluck('id')->all();
        $this->assertSame([$keep['id']], $ids);
        $this->assertSame([], $this->dumper->calls); // menghapus tak pernah menyentuh database
    }

    public function test_admin_can_delete_backups_too(): void
    {
        $this->actingAsRole('admin');
        $backup = $this->createBackup();

        $this->deleteJson("/api/v1/backups/{$backup['id']}")->assertNoContent();
    }

    public function test_deleting_a_backup_removes_its_payment_proofs_archive_with_it(): void
    {
        $this->actingAsRole('owner');
        Storage::disk('local')->put('payment-proofs/bukti-1.jpg', 'fake-image-bytes');
        $backup = $this->createBackup();
        $this->assertFileExists("{$this->backupDir}/{$backup['id']}/payment-proofs.tar.gz");

        $this->deleteJson("/api/v1/backups/{$backup['id']}")->assertNoContent();

        $this->assertDirectoryDoesNotExist("{$this->backupDir}/{$backup['id']}"); // bukan hanya database.sql
        // Bukti pembayaran ASLI di disk tidak ikut terhapus — hanya arsip salinannya.
        Storage::disk('local')->assertExists('payment-proofs/bukti-1.jpg');
    }

    public function test_deleting_an_unknown_or_malformed_id_is_a_404_and_touches_no_backup(): void
    {
        $this->actingAsRole('owner');
        $known = $this->createBackup();

        $this->deleteJson('/api/v1/backups/2020-01-01_000000')->assertStatus(404);
        $this->deleteJson('/api/v1/backups/not-an-id')->assertStatus(404);
        $this->deleteJson('/api/v1/backups/..%2F..')->assertStatus(404);

        $this->assertFileExists("{$this->backupDir}/{$known['id']}/database.sql");
        $this->assertDirectoryExists($this->backupDir);

        // Id yang SAH memang bisa dihapus — jadi 404 di atas datang dari validasi id,
        // bukan dari rute yang belum ada.
        $this->deleteJson("/api/v1/backups/{$known['id']}")->assertNoContent();
    }

    public function test_a_deleted_backup_cannot_be_downloaded_or_restored_any_more(): void
    {
        $this->actingAsRole('owner');
        $backup = $this->createBackup();
        $this->deleteJson("/api/v1/backups/{$backup['id']}")->assertNoContent();
        $this->dumper->calls = [];

        $this->getJson("/api/v1/backups/{$backup['id']}/download")->assertStatus(404);
        $this->postJson("/api/v1/backups/{$backup['id']}/restore", ['confirm' => 'RESTORE'])->assertStatus(404);
        $this->deleteJson("/api/v1/backups/{$backup['id']}")->assertStatus(404); // hapus dua kali
        $this->assertSame([], $this->dumper->calls);
    }

    public function test_deleting_a_backup_leaves_an_audit_line_with_who_and_which(): void
    {
        $user = $this->actingAsRole('owner');
        Log::spy();
        $backup = $this->createBackup();

        $this->deleteJson("/api/v1/backups/{$backup['id']}")->assertNoContent();

        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $context = []) => str_contains($message, 'delet')
                && ($context['backup'] ?? null) === $backup['id']
                && ($context['user_id'] ?? null) === $user->id
        )->once();
    }
}
