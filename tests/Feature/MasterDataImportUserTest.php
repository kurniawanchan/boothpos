<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Support\MasterDataSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * User Story 4 (T049) — sheet 'roles' dan 'users' pada workbook gabungan
 * impor/ekspor data master. Lihat MasterDataImportService untuk aturan
 * lengkap; test ini fokus pada tiga hal yang FR-009/FR-007/contracts/api.md
 * secara eksplisit mensyaratkan:
 *
 *   1. 'roles' diproses SEBELUM 'users' (urutan dependensi).
 *   2. Baris 'users' yang menunjuk role_code yang tidak ada -> 422 per-baris
 *      DAN seluruh impor batal (all-or-nothing), termasuk baris 'roles'
 *      yang valid pada berkas yang sama.
 *   3. Ekspor 'users' tidak pernah memuat kolom password, dan pengguna baru
 *      hasil impor mendapat password acak yang dibuat server (tidak pernah
 *      dari nilai yang dikirim berkas).
 */
class MasterDataImportUserTest extends TestCase
{
    use RefreshDatabase;

    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    private function actingAsOwner(): User
    {
        $user = User::factory()->create(['role' => 'owner']);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function buildWorkbook(array $sheetsData): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        foreach ($sheetsData as $sheetName => $rows) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($sheetName);
            $sheet->fromArray(MasterDataSheets::headings($sheetName), null, 'A1');
            $sheet->fromArray($rows, null, 'A2');
        }

        $path = tempnam(sys_get_temp_dir(), 'mdiu').'.xlsx';
        $this->tempFiles[] = $path;
        (new XlsxWriter($spreadsheet))->save($path);

        return new UploadedFile($path, 'impor.xlsx', null, null, true);
    }

    private function postImport(UploadedFile $file, bool $dryRun = false)
    {
        return $this->post('/api/v1/imports/master-data', [
            'file' => $file,
            'dry_run' => $dryRun ? 1 : 0,
        ]);
    }

    public function test_roles_sheet_is_processed_before_users_sheet_so_a_new_role_can_be_referenced_immediately(): void
    {
        $this->actingAsOwner();

        $file = $this->buildWorkbook([
            MasterDataSheets::ROLES => [
                ['Kasir Cabang 2', 'pos,session'],
            ],
            MasterDataSheets::USERS => [
                ['Budi Santoso', 'budi.kasir2', 'Kasir Cabang 2', 1],
            ],
        ]);

        $this->postImport($file)
            ->assertOk()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('errors', []);

        $this->assertDatabaseHas('roles', ['name' => 'Kasir Cabang 2']);
        $this->assertDatabaseHas('users', ['username' => 'budi.kasir2']);

        $role = Role::where('name', 'Kasir Cabang 2')->firstOrFail();
        $user = User::where('username', 'budi.kasir2')->firstOrFail();
        $this->assertSame($role->id, $user->role_id);
        $this->assertSame(['pos', 'session'], $role->menu_keys);
    }

    public function test_a_users_row_referencing_a_nonexistent_role_rolls_back_the_whole_import(): void
    {
        $this->actingAsOwner();

        $file = $this->buildWorkbook([
            MasterDataSheets::ROLES => [
                ['Kasir Cabang 3', 'pos'],
            ],
            MasterDataSheets::USERS => [
                ['Rina', 'rina.kasir', 'Peran Tidak Ada', 1],
            ],
        ]);

        $response = $this->postImport($file)
            ->assertStatus(422)
            ->assertJsonPath('applied', false);

        $errors = $response->json('errors');
        $this->assertNotEmpty($errors);
        $rowError = collect($errors)->firstWhere('sheet', MasterDataSheets::USERS);
        $this->assertNotNull($rowError, 'Galat baris users tidak ditemukan.');
        $this->assertStringContainsString('Peran Tidak Ada', $rowError['message']);

        // Semua-atau-tidak sama sekali: baris 'roles' yang valid pada
        // berkas yang sama IKUT batal, bukan diterapkan sebagian.
        $this->assertDatabaseMissing('roles', ['name' => 'Kasir Cabang 3']);
        $this->assertDatabaseMissing('users', ['username' => 'rina.kasir']);
    }

    public function test_export_users_never_includes_a_password_column(): void
    {
        $this->actingAsOwner();

        $response = $this->get('/api/v1/exports/users');
        $response->assertOk();

        $this->assertStringNotContainsString(
            'password',
            strtolower($response->headers->get('content-disposition') ?? '')
        );

        // Judul kolom dari sumber tunggal MasterDataSheets — pembuktian
        // langsung bahwa 'password' tidak ada di antara kolom ekspor.
        $this->assertNotContains('password', MasterDataSheets::headings(MasterDataSheets::USERS));
    }

    public function test_new_user_created_via_import_receives_a_system_generated_temporary_password_not_a_client_supplied_one(): void
    {
        $this->actingAsOwner();

        $file = $this->buildWorkbook([
            MasterDataSheets::ROLES => [
                ['Kasir Cabang 4', 'pos'],
            ],
            MasterDataSheets::USERS => [
                ['Sari', 'sari.kasir', 'Kasir Cabang 4', 1],
            ],
        ]);

        $this->postImport($file)->assertOk()->assertJsonPath('applied', true);

        $user = User::where('username', 'sari.kasir')->firstOrFail();

        // users sheet tidak punya kolom password sama sekali (FR-007) —
        // password tersimpan tetap ter-hash dan BUKAN string kosong/null.
        $this->assertNotEmpty($user->password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::needsRehash($user->password) === false);

        // 002-language-toggle US2 — users sheet tidak punya kolom bahasa
        // sama sekali; default 'en' murni dari kolom database
        // (users.language), tidak perlu perubahan apa pun di
        // MasterDataSheets/MasterDataImportService untuk ini.
        $this->assertSame('en', $user->language);
    }
}
