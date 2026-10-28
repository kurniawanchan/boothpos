<?php

namespace Tests\Feature;

use App\Exports\GenericArrayExport;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * 050 — keamanan ekspor: teks yang diketik pengguna dan berawalan "=" tidak boleh menjadi RUMUS di Excel.
 * Sengaja hanya awalan "=": itu satu-satunya awalan yang oleh PhpSpreadsheet diubah menjadi rumus.
 */
class GenericArrayExportTest extends TestCase
{
    private function load(array $rows)
    {
        $path = tempnam(sys_get_temp_dir(), 'gx').'.xlsx';
        file_put_contents($path, Excel::raw(new GenericArrayExport($rows), \Maatwebsite\Excel\Excel::XLSX));
        try {
            return IOFactory::load($path)->getActiveSheet();
        } finally {
            @unlink($path);
        }
    }

    public function test_a_string_starting_with_equals_is_stored_as_text_not_a_formula(): void
    {
        $sheet = $this->load([['name' => '=1+1', 'other' => '=HYPERLINK("http://x.test","a")']]);

        foreach (['A2', 'B2'] as $coordinate) {
            $this->assertSame('s', $sheet->getCell($coordinate)->getDataType(), $coordinate);
        }
        $this->assertSame('=1+1', $sheet->getCell('A2')->getValue());
    }

    public function test_numbers_and_ordinary_text_keep_their_usual_types(): void
    {
        $sheet = $this->load([['n' => 7, 'text' => 'biasa', 'amount' => 12.5]]);

        $this->assertSame('n', $sheet->getCell('A2')->getDataType());
        $this->assertSame('s', $sheet->getCell('B2')->getDataType());
        $this->assertSame('n', $sheet->getCell('C2')->getDataType());
    }

    /** Ditemukan lewat suite penuh: laporan profit membawa sel berisi array; pengikat harus tetap meng-JSON-kannya. */
    public function test_a_cell_holding_an_array_is_still_written_as_json_text(): void
    {
        $sheet = $this->load([['detail' => ['a' => 1], 'text' => '=1+1']]);

        $this->assertSame('{"a":1}', $sheet->getCell('A2')->getValue());
        $this->assertSame('s', $sheet->getCell('B2')->getDataType());
    }
}
