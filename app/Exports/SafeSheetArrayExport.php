<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * 088 — SheetArrayExport (sheet ber-nama, judul kolom eksplisit) dengan aturan SafeStringExport (078): setiap string
 * disimpan sebagai teks literal, jadi nama bahan seperti `=HYPERLINK(...)` tidak pernah dieksekusi. Angka tetap angka.
 * Hanya untuk laporan yang tidak diimpor ulang.
 */
class SafeSheetArrayExport extends SheetArrayExport implements WithCustomValueBinder
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
