<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * 019-billing-system (research.md R6') — satu sheet mandiri (bukan bagian
 * dari MasterDataSheets::ORDER, invoice adalah data transaksional), baris
 * pertama judul kolom. Meniru persis App\Imports\PreorderImport: array()
 * sengaja kosong, logika validasi/penerapan seluruhnya ada di
 * InvoiceExportImportService, di dalam SATU transaksi tunggal.
 */
class InvoiceImport implements ToArray, WithHeadingRow
{
    public function array(array $array): void {}
}
