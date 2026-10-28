<?php

namespace Tests\Feature;

use App\Exports\GenericArrayExport;
use App\Exports\SafeStringExport;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * 078 (tinjauan keamanan) — teks bebas buatan kasir (catatan order, nama pelanggan/barang) tidak boleh menjadi formula spreadsheet.
 * Pengikat bawaan menganggap string berawalan "=" sebagai formula; SafeStringExport menyimpannya sebagai teks literal.
 */
class SafeStringExportTest extends TestCase
{
    private function sheet(object $export)
    {
        $path = tempnam(sys_get_temp_dir(), 'xl').'.xlsx';
        file_put_contents($path, Excel::raw($export, ExcelWriter::XLSX));
        try {
            return IOFactory::load($path)->getActiveSheet();
        } finally {
            @unlink($path);
        }
    }

    private const ROWS = [['order_note' => '=HYPERLINK("http://x","klik")', 'customer' => '+1+1', 'items' => '-2+3', 'cashier' => '@SUM(1)', 'total' => 5000.0]];

    public function test_the_default_export_would_turn_a_leading_equals_into_a_formula(): void
    {
        // Kontrol: membuktikan tes ini peka — pengikat bawaan memang membuat formula.
        $this->assertSame('f', $this->sheet(new GenericArrayExport(self::ROWS))->getCell('A2')->getDataType());
    }

    public function test_every_free_text_column_is_stored_as_literal_text(): void
    {
        $sheet = $this->sheet(new SafeStringExport(self::ROWS));

        foreach (['A2' => '=HYPERLINK("http://x","klik")', 'B2' => '+1+1', 'C2' => '-2+3', 'D2' => '@SUM(1)'] as $cell => $text) {
            $this->assertSame('s', $sheet->getCell($cell)->getDataType(), "$cell harus teks");
            $this->assertSame($text, $sheet->getCell($cell)->getValue(), "$cell harus utuh, tanpa awalan");
        }
    }

    public function test_numbers_stay_numeric_so_excel_can_sum_them(): void
    {
        $cell = $this->sheet(new SafeStringExport(self::ROWS))->getCell('E2');

        $this->assertSame('n', $cell->getDataType());
        $this->assertEquals(5000, $cell->getValue());
    }

    public function test_the_sales_export_uses_the_safe_export(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/Api/ReportController.php'));

        $this->assertStringContainsString("new \\App\\Exports\\SafeStringExport(\$rows), 'transaksi-penjualan.xlsx'", $source);
    }
}
