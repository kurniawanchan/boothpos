<?php

namespace App\Exports;

use App\Exports\Concerns\KeepsFormulaLookingTextAsText;
use App\Support\CatalogImageThumbnail;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 056 (US8) — ekspor daftar Sales: satu baris = satu penjualan, semua kolom yang tampil di layar, lalu catatan
 * Sale Reference dan GAMBAR tertanam di baris yang sama (gambar referensi, lalu bukti bayar yang boleh dilihat
 * pengekspor). Tiap gambar punya kolomnya sendiri (offset di dalam satu sel tak andal di Excel).
 *
 * Baris masuk berupa array asosiatif; kunci berawalan `_` BUKAN kolom, melainkan data gambar yang dipakai
 * kelas ini saja: `_reference_images` = list path (disk `local`), `_proof_images` = list path (disk `local`).
 * Pola berkas sementara sama dengan ProductCatalogExport: pemanggil WAJIB memanggil cleanup() sesudah workbook jadi.
 * Gambar hilang/rusak/bukan gambar (mis. PDF) tidak menjatuhkan ekspor: selnya berisi teks "tidak dapat ditanam".
 */
class SalesTransactionsExport implements FromArray, WithCustomValueBinder, WithDrawings, WithHeadings, WithStyles
{
    use KeepsFormulaLookingTextAsText;

    private const IMAGE_ROW_HEIGHT_PT = 70;

    private const IMAGE_HEIGHT_PX = 88;

    private ?string $tempDir = null;

    /** @var list<array{row:int, col:int, path:string}> */
    private array $placements = [];

    /** @var array<int, true> baris (1-based, termasuk judul) yang memuat gambar */
    private array $imageRows = [];

    private int $dataColumns = 0;

    private int $refColumns = 0;

    private int $proofColumns = 0;

    /** @var list<array<string, mixed>> baris tanpa kunci pribadi */
    private array $rows = [];

    /** @param  list<array<string, mixed>>  $rows */
    public function __construct(private readonly array $rows_in)
    {
        $this->prepare();
    }

    private function prepare(): void
    {
        $first = $this->rows_in[0] ?? [];
        $this->dataColumns = count(array_filter(array_keys($first), fn ($k) => ! str_starts_with((string) $k, '_')));
        foreach ($this->rows_in as $row) {
            $this->refColumns = max($this->refColumns, count($row['_reference_images'] ?? []));
            $this->proofColumns = max($this->proofColumns, count($row['_proof_images'] ?? []));
        }

        foreach ($this->rows_in as $i => $row) {
            $this->rows[] = array_filter($row, fn ($k) => ! str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_KEY);
        }
    }

    /** @var list<list<mixed>>|null hasil perhitungan (sekali): sel tiap baris + penempatan gambar */
    private ?array $computed = null;

    /**
     * Menghitung sel dan penempatan gambar SEKALI. Dipanggil dari array() maupun drawings() karena Maatwebsite
     * tidak menjamin urutan keduanya; hasilnya sama untuk keduanya.
     */
    private function compute(): array
    {
        if ($this->computed !== null) {
            return $this->computed;
        }
        $out = [];
        foreach ($this->rows_in as $i => $row) {
            $r = $i + 2; // baris 1 = judul
            $cells = array_fill(0, $this->refColumns + $this->proofColumns, '');
            foreach ([['_reference_images', 0], ['_proof_images', $this->refColumns]] as [$key, $offset]) {
                foreach (($row[$key] ?? []) as $n => $path) {
                    $jpeg = CatalogImageThumbnail::jpeg($path, 240, 40_000_000, 'local');
                    if ($jpeg === null) {
                        $cells[$offset + $n] = __('sales_export.not_embedded');

                        continue;
                    }
                    $this->placements[] = ['row' => $r, 'col' => $this->dataColumns + $offset + $n + 1, 'path' => $this->tempFile($jpeg)];
                    $this->imageRows[$r] = true;
                }
            }
            $out[] = array_merge(array_values($this->rows[$i]), $cells);
        }

        return $this->computed = $out;
    }

    public function array(): array
    {
        return $this->compute();
    }

    /** Baris asosiatif apa adanya (tanpa kolom gambar) — dipakai pengujian dan pemeriksaan isi. */
    public function rows(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        if ($this->rows === []) {
            return [];
        }
        $heads = array_keys($this->rows[0]);
        for ($n = 1; $n <= $this->refColumns; $n++) {
            $heads[] = 'reference_image_'.$n;
        }
        for ($n = 1; $n <= $this->proofColumns; $n++) {
            $heads[] = 'payment_proof_image_'.$n;
        }

        return $heads;
    }

    public function styles(Worksheet $sheet): array
    {
        $this->compute();
        $sheet->getStyle('1:1')->getFont()->setBold(true);
        foreach (array_keys($this->imageRows) as $row) {
            $sheet->getRowDimension($row)->setRowHeight(self::IMAGE_ROW_HEIGHT_PT);
        }
        for ($c = $this->dataColumns + 1; $c <= $this->dataColumns + $this->refColumns + $this->proofColumns; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(18);
        }

        return [];
    }

    /** @return list<Drawing> */
    public function drawings(): array
    {
        $this->compute();
        $drawings = [];
        foreach ($this->placements as $p) {
            $d = new Drawing;
            $d->setName('Gambar');
            $d->setPath($p['path']);
            $d->setCoordinates(Coordinate::stringFromColumnIndex($p['col']).$p['row']);
            $d->setOffsetX(3);
            $d->setOffsetY(3);
            $d->setHeight(self::IMAGE_HEIGHT_PX);
            $drawings[] = $d;
        }

        return $drawings;
    }

    private function tempFile(string $bytes): string
    {
        $this->tempDir ??= sys_get_temp_dir().DIRECTORY_SEPARATOR.'boothpos-sales-'.bin2hex(random_bytes(6));
        if (! is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0700, true);
        }
        $path = $this->tempDir.DIRECTORY_SEPARATOR.bin2hex(random_bytes(6)).'.jpg';
        file_put_contents($path, $bytes);

        return $path;
    }

    public function cleanup(): void
    {
        if ($this->tempDir && is_dir($this->tempDir)) {
            foreach (glob($this->tempDir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tempDir);
        }
        $this->tempDir = null;
    }
}
