<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\MasterDataSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/** 057 (FR-007/FR-008) — sheet 'roles': kolom opsional `menu_actions`; berkas format lama tetap diterima (= akses penuh). */
class RoleMenuActionsImportExportTest extends TestCase
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

    /** @param  list<string>  $headers  @param  list<list<mixed>>  $rows */
    private function workbook(array $headers, array $rows): UploadedFile
    {
        $ss = new Spreadsheet;
        $ss->removeSheetByIndex(0);
        $sheet = $ss->createSheet();
        $sheet->setTitle(MasterDataSheets::ROLES);
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'rma').'.xlsx';
        $this->temp[] = $path;
        (new XlsxWriter($ss))->save($path);

        return new UploadedFile($path, 'peran.xlsx', null, null, true);
    }

    private function import(UploadedFile $f)
    {
        return $this->post('/api/v1/imports/master-data', ['file' => $f, 'dry_run' => 0]);
    }

    public function test_the_roles_sheet_has_the_optional_menu_actions_column(): void
    {
        $this->assertSame(['name', 'menu_keys', 'menu_actions'], MasterDataSheets::headings(MasterDataSheets::ROLES));
    }

    public function test_a_legacy_file_without_the_column_imports_as_full_access(): void
    {
        $this->owner();

        $this->import($this->workbook(['name', 'menu_keys'], [['Lama', 'sales,pos']]))->assertOk();

        $role = Role::where('name', 'Lama')->first();
        $this->assertNull($role->menu_actions);
        $this->assertTrue($role->allows('sales', 'void'));
    }

    public function test_a_menu_actions_cell_is_parsed_and_stored(): void
    {
        $this->owner();

        $this->import($this->workbook(MasterDataSheets::headings(MasterDataSheets::ROLES), [['Terbatas', 'sales,pos', 'sales=void+export;pos=']]))->assertOk();

        $role = Role::where('name', 'Terbatas')->first();
        // MySQL JSON tidak menjaga urutan kunci: bandingkan tanpa urutan.
        $this->assertEquals(['sales' => ['void', 'export'], 'pos' => []], $role->menu_actions);
        $this->assertFalse($role->allows('sales', 'verify'));
        $this->assertTrue($role->allows('sales', 'void'));
    }

    public function test_a_blank_cell_leaves_an_existing_roles_actions_unchanged(): void
    {
        $this->owner();
        Role::factory()->create(['name' => 'Tetap', 'menu_keys' => ['sales'], 'menu_actions' => ['sales' => ['void']]]);

        $this->import($this->workbook(MasterDataSheets::headings(MasterDataSheets::ROLES), [['Tetap', 'sales', '']]))->assertOk();

        $this->assertSame(['sales' => ['void']], Role::where('name', 'Tetap')->first()->menu_actions);
    }

    public function test_bad_cells_are_row_level_errors_and_nothing_is_imported(): void
    {
        $this->owner();
        $headers = MasterDataSheets::headings(MasterDataSheets::ROLES);

        foreach ([
            'sales=hack' => 'tidak dikenal',          // aksi tak ada
            'nope=void' => 'tidak dikenal',           // menu tak ada
            'customers=create' => 'bukan bagian',     // menu tak dimiliki peran (keys = sales)
            'sales void' => 'format',                 // tanpa '='
        ] as $cell => $needle) {
            $response = $this->import($this->workbook($headers, [['Salah', 'sales', $cell]]))->assertStatus(422);
            $this->assertStringContainsStringIgnoringCase($needle, json_encode($response->json(), JSON_UNESCAPED_UNICODE), $cell);
            $this->assertDatabaseMissing('roles', ['name' => 'Salah']);
        }
    }

    public function test_the_owner_role_cannot_be_restricted_by_import(): void
    {
        $this->owner();
        $owner = Role::where('name', 'Owner')->first();
        $keys = implode(',', $owner->menu_keys);

        $this->import($this->workbook(MasterDataSheets::headings(MasterDataSheets::ROLES), [['Owner', $keys, 'sales=']]))->assertStatus(422);

        $this->assertNull($owner->fresh()->menu_actions);
    }

    public function test_export_writes_the_cell_and_it_round_trips(): void
    {
        $this->owner();
        Role::factory()->create(['name' => 'Bolak', 'menu_keys' => ['sales', 'pos'], 'menu_actions' => ['sales' => ['void', 'export'], 'pos' => []]]);

        $response = $this->get('/api/v1/exports/roles')->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        $sheet = IOFactory::load($file)->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);
        $head = array_flip($rows[0]);
        $row = collect($rows)->first(fn ($r) => ($r[$head['name']] ?? null) === 'Bolak');
        $this->assertSame('sales=void+export;pos=', $row[$head['menu_actions']]);

        // impor ulang baris yang sama menghasilkan data yang sama
        Role::where('name', 'Bolak')->update(['menu_actions' => null]);
        $this->import($this->workbook(MasterDataSheets::headings(MasterDataSheets::ROLES), [['Bolak', $row[$head['menu_keys']], $row[$head['menu_actions']]]]))->assertOk();
        $this->assertEquals(['sales' => ['void', 'export'], 'pos' => []], Role::where('name', 'Bolak')->first()->menu_actions);
    }
}
