<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Concerns\SkipsUnknownSheets;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Pembaca workbook impor gabungan. Hanya bertugas MEMBACA sheet menjadi
 * array — tidak ada satu pun tulisan ke database di sini. Seluruh
 * validasi dan penerapan ada di MasterDataImportService, karena impor
 * wajib divalidasi sepenuhnya dulu baru diterapkan sekaligus dalam satu
 * transaksi (semua-atau-tidak sama sekali).
 *
 * Kunci sheets() sengaja diisi nama sheet APA ADANYA dari berkas pengguna
 * (bukan nama kanonik), karena PhpSpreadsheet mencari sheet berdasarkan
 * nama persis. Pemetaan ke nama kanonik ('artists', 'categories',
 * 'products', 'stock') dilakukan pemanggil lewat
 * MasterDataSheets::canonicalName(), sehingga "Products" atau " stock "
 * tetap dikenali.
 */
class MasterDataSheetsImport implements Import, SkipsUnknownSheets, WithMultipleSheets
{
    /**
     * @param  string[]  $sheetNames  nama sheet apa adanya yang ingin dibaca
     */
    public function __construct(private array $sheetNames) {}

    public function sheets(): array
    {
        $sheets = [];

        foreach ($this->sheetNames as $name) {
            $sheets[$name] = new HeadingRowSheetImport;
        }

        return $sheets;
    }

    /**
     * Tidak mungkin terjadi pada alur normal (pemanggil hanya meminta sheet
     * yang benar-benar ada di berkas), tapi wajib ada supaya sheet yang
     * hilang di tengah jalan tidak melempar exception dan menggagalkan
     * seluruh impor.
     */
    public function onUnknownSheet(string|int $sheetName): void {}
}
