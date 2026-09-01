<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Satu sheet ber-NAMA dengan judul kolom EKSPLISIT.
 *
 * Bedanya dengan GenericArrayExport (dipakai ReportController): kelas itu
 * menurunkan judul kolom dari kunci baris pertama, jadi menghasilkan sheet
 * tanpa judul sama sekali bila datanya kosong. Untuk ekspor master data
 * dan terutama untuk TEMPLATE impor (yang memang tidak punya baris data),
 * judul kolom justru satu-satunya isi yang penting — jadi harus
 * ditentukan eksplisit, bukan ditebak dari data.
 *
 * Nama sheet ikut penting: berkas hasil ekspor dirancang bisa langsung
 * diunggah kembali ke endpoint impor, dan impor mengenali sheet dari
 * namanya.
 *
 * CATATAN PERLINDUNGAN DATA: sama seperti GenericArrayExport — penyaringan
 * kolom sensitif (mis. kontak pelanggan) adalah tanggung jawab pemanggil,
 * bukan kelas ini.
 */
class SheetArrayExport implements FromArray, WithHeadings, WithTitle
{
    public function __construct(
        private string $title,
        private array $headings,
        private array $rows,
    ) {}

    public function array(): array
    {
        // Diurutkan ulang mengikuti $headings supaya kolom selalu sejajar
        // dengan judulnya, meski pemanggil menyusun kunci dengan urutan
        // berbeda.
        return array_map(function ($row) {
            $row = (array) $row;

            return array_map(fn (string $heading) => $row[$heading] ?? null, $this->headings);
        }, $this->rows);
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return $this->title;
    }
}
