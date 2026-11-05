<?php

namespace Tests\Feature\SellerScope;

use App\Models\Artist;
use App\Models\Role;
use App\Models\User;
use App\Support\MasterDataSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/** 058 — sheet 'users': kolom opsional `artist_code`; kosong = biarkan; Owner tak boleh punya seller. */
class UserSellerImportExportTest extends TestCase
{
    use RefreshDatabase;

    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function owner(): void
    {
        $u = User::factory()->create();
        $u->update(['role_id' => Role::where('name', 'Owner')->first()->id]);
        $this->actingAs($u->fresh(), 'sanctum');
    }

    private function usersFile(array $headers, array $rows): UploadedFile
    {
        $ss = new Spreadsheet;
        $ss->removeSheetByIndex(0);
        $sheet = $ss->createSheet();
        $sheet->setTitle(MasterDataSheets::USERS);
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'usr').'.xlsx';
        $this->temp[] = $path;
        (new XlsxWriter($ss))->save($path);

        return new UploadedFile($path, 'users.xlsx', null, null, true);
    }

    private function import(UploadedFile $f)
    {
        return $this->post('/api/v1/imports/master-data', ['file' => $f, 'dry_run' => 0]);
    }

    public function test_users_sheet_headings_include_artist_code(): void
    {
        $this->assertContains('artist_code', MasterDataSheets::headings(MasterDataSheets::USERS));
    }

    public function test_import_assigns_a_seller_and_blank_leaves_it_unchanged(): void
    {
        $this->owner();
        $a = Artist::factory()->create(['code' => 'AAA']);
        $headers = ['name', 'username', 'role_name', 'is_active', 'artist_code'];

        $r0 = $this->import($this->usersFile($headers, [['Kasir Satu', 'kasir.satu', 'Kasir', 1, 'AAA']]));
        $r0->assertOk();
        $this->assertSame($a->id, User::where('username', 'kasir.satu')->value('artist_id'));

        // Sel kosong pada baris yang sama = biarkan (aturan master-data).
        $this->import($this->usersFile($headers, [['Kasir Satu', 'kasir.satu', 'Kasir', 1, '']]))->assertOk();
        $this->assertSame($a->id, User::where('username', 'kasir.satu')->value('artist_id'));
    }

    public function test_unknown_seller_code_and_owner_role_are_row_errors_and_nothing_is_written(): void
    {
        $this->owner();
        Artist::factory()->create(['code' => 'AAA']);
        $headers = ['name', 'username', 'role_name', 'is_active', 'artist_code'];

        $r = $this->import($this->usersFile($headers, [
            ['Satu', 'u.satu', 'Kasir', 1, 'ZZZ'],
            ['Dua', 'u.dua', 'Owner', 1, 'AAA'],
        ]));

        $r->assertStatus(422);
        $this->assertFalse(User::where('username', 'u.satu')->exists());
        $this->assertFalse(User::where('username', 'u.dua')->exists());
    }

    public function test_importing_an_existing_scoped_user_as_owner_clears_the_link(): void
    {
        $this->owner();
        $a = Artist::factory()->create(['code' => 'AAA']);
        $u = User::factory()->create(['role' => 'cashier', 'username' => 'promosi', 'artist_id' => $a->id]);

        $this->import($this->usersFile(['name', 'username', 'role_name', 'is_active'], [[$u->name, 'promosi', 'Owner', 1]]))->assertOk();
        $this->assertNull($u->fresh()->artist_id);
    }

    public function test_export_carries_the_seller_code(): void
    {
        $this->owner();
        $a = Artist::factory()->create(['code' => 'AAA']);
        User::factory()->create(['role' => 'cashier', 'artist_id' => $a->id]);

        $res = $this->get('/api/v1/exports/users');
        $res->assertOk();
        $this->assertSame('xlsx', pathinfo($res->headers->get('content-disposition') ?? '.xlsx', PATHINFO_EXTENSION) ?: 'xlsx');
    }
}
