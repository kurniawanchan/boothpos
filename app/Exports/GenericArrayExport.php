<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Export generik dari array asosiatif. Cukup untuk kebutuhan MVP;
 * bila format per-laporan perlu berbeda (styling, multi-sheet), pisahkan
 * jadi class Export khusus per laporan alih-alih menumpuk kondisi di sini.
 *
 * CATATAN PERLINDUNGAN DATA: pastikan $rows yang dikirim ke sini SUDAH
 * tidak menyertakan kolom kontak pelanggan sebelum sampai di sini —
 * tanggung jawab ini ada di controller pemanggil (ReportController),
 * bukan di kelas export ini.
 */
class GenericArrayExport implements FromArray, WithCustomValueBinder, WithHeadings
{
    public function __construct(private array $rows) {}

    /**
     * Keamanan (formula injection, 050): pengikat bawaan PhpSpreadsheet mengubah teks berawalan `=` menjadi RUMUS,
     * padahal banyak sel di sini diketik pengguna (nama pelanggan, nama varian, catatan). Teks seperti
     * `=HYPERLINK(...)` akan dijalankan saat berkas dibuka. Hanya awalan `=` yang dipaksa menjadi teks — itu satu-satunya
     * awalan yang diubah menjadi rumus; memaksa SEMUA string menjadi teks akan mengubah kolom bertipe angka-berbentuk-teks
     * pada ekspor laporan dan bisa memutus impor ulang, jadi sengaja tidak dilakukan di sini.
     */
    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value) && str_starts_with($value, '=')) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        // Pengikat Maatwebsite (bukan milik PhpSpreadsheet): ia meng-JSON-kan sel berisi array — beberapa baris laporan
        // (profit/seller cost) memang membawa array, dan pengikat PhpSpreadsheet melempar "Unable to bind unstringable array".
        return (new DefaultValueBinder)->bindValue($cell, $value);
    }

    public function array(): array
    {
        return array_map(fn ($row) => (array) $row, $this->rows);
    }

    public function headings(): array
    {
        if (empty($this->rows)) {
            return [];
        }

        return array_keys((array) $this->rows[0]);
    }
}
