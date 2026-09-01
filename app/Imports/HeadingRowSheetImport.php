<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Satu sheet yang dibaca dengan baris pertama sebagai judul kolom.
 *
 * array() dibiarkan kosong dengan sengaja: kelas ini hanya dipakai lewat
 * Excel::toArray(), yang MENGEMBALIKAN isi sheet alih-alih memanggil
 * array(). Menaruh logika impor di sini justru akan menjalankannya di luar
 * transaksi tunggal yang dipegang MasterDataImportService.
 */
class HeadingRowSheetImport implements ToArray, WithHeadingRow
{
    public function array(array $array): void {}
}
