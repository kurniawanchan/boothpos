<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

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
class GenericArrayExport implements FromArray, WithHeadings
{
    public function __construct(private array $rows) {}

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
