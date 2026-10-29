<?php

namespace App\Exports\Concerns;

use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Keamanan (formula injection, 050/051): pengikat bawaan PhpSpreadsheet mengubah teks berawalan `=` menjadi RUMUS,
 * padahal banyak sel ekspor diketik pengguna (nama pelanggan, penjual, produk, varian, catatan). Teks seperti
 * `=HYPERLINK(...)` akan dijalankan saat berkas dibuka.
 *
 * Hanya awalan `=` yang dipaksa menjadi teks — itu satu-satunya awalan yang diubah PhpSpreadsheet menjadi rumus; memaksa
 * SEMUA string menjadi teks akan mengubah kolom angka-berbentuk-teks pada ekspor laporan dan bisa memutus impor ulang.
 * Selebihnya diserahkan ke pengikat Maatwebsite (bukan milik PhpSpreadsheet): ia meng-JSON-kan sel berisi array — beberapa
 * baris laporan (profit/seller cost) memang membawa array, dan pengikat PhpSpreadsheet melempar "Unable to bind unstringable array".
 *
 * Dipakai bersama oleh GenericArrayExport dan SheetArrayExport: satu implementasi, bukan salinan per kelas (Konstitusi I).
 * Kelas pemakai harus `implements WithCustomValueBinder`.
 */
trait KeepsFormulaLookingTextAsText
{
    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value) && str_starts_with($value, '=')) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return (new DefaultValueBinder)->bindValue($cell, $value);
    }
}
