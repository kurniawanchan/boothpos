<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * 020-customer-data-import-export — satu sheet, baris pertama judul kolom.
 * array() sengaja dibiarkan kosong seperti PreorderImport: kelas ini hanya
 * dipakai lewat Excel::toArray(), bukan array() langsung — logika
 * validasi/penerapan ada di CustomerExportImportService, di dalam SATU
 * transaksi tunggal.
 */
class CustomerImport implements ToArray, WithHeadingRow
{
    public function array(array $array): void {}
}
