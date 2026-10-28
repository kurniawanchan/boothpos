<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * 078 — export laporan yang menyimpan SETIAP string sebagai teks literal, bukan formula. Pengikat bawaan PhpSpreadsheet menganggap
 * string berawalan "=" sebagai formula, sehingga teks bebas buatan kasir (catatan order, nama pelanggan, nama barang) seperti
 * `=HYPERLINK(...)` akan dieksekusi saat pemilik membuka berkas (injeksi formula spreadsheet). Angka tetap angka (bisa dijumlah).
 *
 * SENGAJA kelas terpisah dan bukan perilaku GenericArrayExport: export pelanggan/faktur dibaca ulang oleh impor, dan awalan apa pun
 * pada nilai akan merusak data balik-alik itu. Pakai kelas ini hanya untuk laporan yang tidak diimpor ulang.
 */
class SafeStringExport extends GenericArrayExport implements WithCustomValueBinder
{
    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return (new DefaultValueBinder)->bindValue($cell, $value);
    }
}
