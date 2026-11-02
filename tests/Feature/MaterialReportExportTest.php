<?php

namespace Tests\Feature;

use App\Exports\MultiSheetArrayExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\MaterialReportFixtures;
use Tests\TestCase;

/**
 * 088 US5 (T038) — GET /reports/materials/export (contracts §5): tiga sheet dengan baris yang SAMA dengan layar untuk
 * saringan yang sama, dan teks bebas (nama bahan) tersimpan sebagai teks, bukan formula (078).
 */
class MaterialReportExportTest extends TestCase
{
    use MaterialReportFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMaterialReportWorld();
        $this->seedRiskScenario();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    public function test_three_sheets_with_the_same_rows_as_the_screen(): void
    {
        Excel::fake();
        $this->withHeader('X-Outlet-Id', (string) $this->outletA->id)->get('/api/v1/reports/materials/export?risk_days=3')->assertOk();
        $screen = $this->getJson('/api/v1/reports/materials/position?risk_days=3')->json('data');
        $usage = $this->getJson('/api/v1/reports/materials/usage')->json('data');

        Excel::assertDownloaded('laporan-bahan.xlsx', function (MultiSheetArrayExport $export) use ($screen, $usage) {
            [$position, $suggestions, $period] = $export->sheets();
            $this->assertSame(['Posisi & risiko', 'Saran pembelian', 'Pemakaian periode'], [$position->title(), $suggestions->title(), $period->title()]);
            $this->assertSame(array_column($screen, 'name'), array_column($position->array(), 1));
            $this->assertSame(count(array_filter($screen, fn ($r) => $r['suggestion'] !== null)), count($suggestions->array()));
            $this->assertCount(count($usage), $period->array());

            return true;
        });
    }

    public function test_text_that_looks_like_a_formula_stays_text(): void
    {
        $this->mat['cabai']->update(['name' => '=SUM(1)']);
        $res = $this->get('/api/v1/reports/materials/export')->assertOk();
        $sheet = IOFactory::load($res->baseResponse->getFile()->getPathname())->getSheetByName('Posisi & risiko');

        $cell = collect($sheet->getRowIterator(2))->map(fn ($row) => $sheet->getCell('B'.$row->getRowIndex()))
            ->first(fn ($c) => $c->getValue() === '=SUM(1)');
        $this->assertNotNull($cell, 'nama bahan berawalan = harus tersimpan apa adanya');
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
    }

    public function test_it_needs_the_reports_menu(): void
    {
        $this->actingAs($this->cashier, 'sanctum');
        $this->getJson('/api/v1/reports/materials/export')->assertForbidden();
    }
}
