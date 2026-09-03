<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * 007-preorder-import-export-notify (US3) — satu sheet, baris pertama
 * judul kolom. array() sengaja dibiarkan kosong seperti
 * HeadingRowSheetImport: kelas ini hanya dipakai lewat Excel::toArray(),
 * bukan array() langsung — logika validasi/penerapan ada di
 * PreorderExportImportService, di dalam SATU transaksi tunggal
 * (research.md R3), bukan tersebar ke kelas Import ini.
 */
class PreorderImport implements ToArray, WithHeadingRow
{
    public function array(array $array): void {}
}
