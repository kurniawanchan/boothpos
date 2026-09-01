<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Membungkus beberapa SheetArrayExport menjadi satu workbook.
 *
 * Dipakai untuk template impor gabungan (4 sheet sekaligus) DAN untuk
 * ekspor satu entitas (1 sheet) — keduanya lewat kelas yang sama supaya
 * ekspor per-entitas pun tetap punya nama sheet kanonik, sehingga berkasnya
 * bisa langsung diunggah kembali ke endpoint impor tanpa disunting dulu.
 *
 * @param  SheetArrayExport[]  $sheets
 */
class MultiSheetArrayExport implements Export, WithMultipleSheets
{
    public function __construct(private array $sheets) {}

    public function sheets(): array
    {
        return $this->sheets;
    }
}
