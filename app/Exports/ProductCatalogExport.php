<?php

namespace App\Exports;

use App\Support\CatalogImageThumbnail;
use App\Support\Code128;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 049-product-variant-catalog-export — katalog Excel: satu sheet, blok identitas toko di atas, lalu per
 * penjual satu baris judul berwarna dan baris-baris variannya. Datanya dari ProductCatalogBuilder yang SAMA
 * dengan PDF, jadi kedua format tidak bisa berbeda.
 *
 * Kode, nama, kategori, dan harga adalah NILAI sel sungguhan (Ctrl+F dan filter bekerja; harga berupa angka
 * berformat Rupiah) — hanya gambar dan barcode yang berupa gambar tertanam (FR-019).
 *
 * Memori: gambar ditulis ke berkas sementara dan dirujuk lewat PATH, sehingga PhpSpreadsheet membacanya satu
 * per satu saat menulis berkas, bukan menahan ratusan resource GD sekaligus (batas 128 MB bawaan PHP sudah
 * pernah menjatuhkan ekspor lain). Pemanggil WAJIB memanggil cleanup() setelah berkas selesai dibuat.
 */
class ProductCatalogExport implements FromArray, WithColumnWidths, WithDrawings, WithStyles, WithTitle
{
    /**
     * ARGB untuk isian sel. Excel tidak mengenal token CSS, jadi INI satu-satunya tempat nilai warna harus
     * ditulis literal; urutan & nilainya meniru pasangan token @theme (mint, sky, violet, warn, danger) yang
     * dipakai aksen PDF, supaya penjual yang sama berwarna serupa di kedua format.
     *
     * @var list<array{fill: string, text: string}>
     */
    private const ACCENTS = [
        ['fill' => 'FFE4F3EC', 'text' => 'FF1E6B4B'],
        ['fill' => 'FFE6F0F7', 'text' => 'FF1F5F8B'],
        ['fill' => 'FFEFEAF8', 'text' => 'FF5B3F99'],
        ['fill' => 'FFFBF7EC', 'text' => 'FF8A6A1E'],
        ['fill' => 'FFFBEDEC', 'text' => 'FFA2534B'],
    ];

    private const BRAND_FILL = 'FF2F9E6E';

    private const VARIANT_ROW_HEIGHT_PT = 56;

    /** @var list<list<string|float|null>> */
    private array $rows = [];

    /** @var array<int, int> nomor baris judul penjual => indeks aksen */
    private array $sellerRows = [];

    /** @var list<array{row: int, code: string, imagePath: ?string, pattern: ?string}> */
    private array $variantRows = [];

    private int $headerRow = 0;

    private ?string $tempDir = null;

    /** @param  array<string, mixed>  $catalog keluaran ProductCatalogBuilder::build($filters, withImagePaths: true) */
    public function __construct(private readonly array $catalog)
    {
        $this->buildRows();
    }

    public function title(): string
    {
        return mb_substr((string) __('catalog.sheet'), 0, 31); // batas nama sheet Excel
    }

    public function array(): array
    {
        return $this->rows;
    }

    private function buildRows(): void
    {
        $store = $this->catalog['store'];
        $contact = array_filter([
            $store['contact_person'] ? __('catalog.contact_person').': '.$store['contact_person'] : null,
            $store['contact_phone'] ? __('catalog.phone').': '.$store['contact_phone'] : null,
            $store['contact_email'] ? __('catalog.email').': '.$store['contact_email'] : null,
        ]);

        // Blok identitas toko; baris yang belum diisi dilewati (tanpa label kosong).
        $this->rows[] = [$store['name']];
        if ($store['address']) {
            $this->rows[] = [$store['address']];
        }
        if ($contact) {
            $this->rows[] = [implode('  ·  ', $contact)];
        }
        $this->rows[] = [__('catalog.generated_on', ['date' => Carbon::parse($this->catalog['generated_at'])->format('d-m-Y H:i')])];
        // Baris pemisah HARUS berisi satu sel kosong: Maatwebsite membuang baris `[]` begitu saja, sehingga semua
        // nomor baris di bawahnya (judul penjual, gaya, tinggi baris) bergeser satu dan penggabungan sel judul
        // menimpa baris varian pertama (ditemukan lewat tes baca-ulang workbook).
        $this->rows[] = [''];

        $this->headerRow = count($this->rows) + 1;
        $this->rows[] = array_map(fn (string $key) => __("catalog.columns.{$key}"), ['seller', 'category', 'product', 'variant', 'code', 'price', 'image', 'barcode']);

        foreach ($this->catalog['sellers'] as $sellerIndex => $seller) {
            $this->rows[] = [__('catalog.seller_heading', ['name' => $seller['name'], 'count' => count($seller['variants'])])];
            $this->sellerRows[count($this->rows)] = $sellerIndex % count(self::ACCENTS);

            foreach ($seller['variants'] as $variant) {
                $this->rows[] = [
                    $seller['name'], $variant['category_name'], $variant['product_name'], $variant['variant_name'],
                    $variant['code'], (float) $variant['sell_price'], null, null,
                ];
                $this->variantRows[] = [
                    'row' => count($this->rows),
                    'code' => $variant['code'],
                    'imagePath' => $variant['image_path'] ?? null,
                    'pattern' => $variant['barcode']['pattern'] ?? null,
                ];
            }
        }
    }

    public function columnWidths(): array
    {
        return ['A' => 22, 'B' => 16, 'C' => 28, 'D' => 28, 'E' => 24, 'F' => 14, 'G' => 13, 'H' => 46];
    }

    public function styles(Worksheet $sheet): array
    {
        $last = count($this->rows);
        $sheet->mergeCells('A1:H1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);

        $header = "A{$this->headerRow}:H{$this->headerRow}";
        $sheet->getStyle($header)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle($header)->getFill()->setFillType('solid')->getStartColor()->setARGB(self::BRAND_FILL);
        $sheet->freezePane('A'.($this->headerRow + 1));

        foreach ($this->sellerRows as $row => $accentIndex) {
            $accent = self::ACCENTS[$accentIndex];
            $sheet->mergeCells("A{$row}:H{$row}");
            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12)->getColor()->setARGB($accent['text']);
            $sheet->getStyle("A{$row}:H{$row}")->getFill()->setFillType('solid')->getStartColor()->setARGB($accent['fill']);
            $sheet->getRowDimension($row)->setRowHeight(22);
        }

        foreach ($this->variantRows as $variant) {
            $sheet->getRowDimension($variant['row'])->setRowHeight(self::VARIANT_ROW_HEIGHT_PT);
            $sheet->getStyle("A{$variant['row']}:F{$variant['row']}")->getAlignment()->setVertical('center')->setWrapText(true);
        }
        if ($this->variantRows) {
            $first = $this->variantRows[0]['row'];
            $sheet->getStyle("F{$first}:F{$last}")->getNumberFormat()->setFormatCode('"Rp" #,##0');
        }

        return [];
    }

    /** @return list<Drawing> */
    public function drawings(): array
    {
        $drawings = [];
        foreach ($this->variantRows as $variant) {
            $thumb = CatalogImageThumbnail::jpeg($variant['imagePath']);
            if ($thumb !== null) {
                $drawings[] = $this->drawing($this->tempFile($thumb, 'jpg'), "G{$variant['row']}", 'Gambar '.$variant['code'], height: 66, width: null);
            }
            if ($variant['pattern'] !== null) {
                // PNG beresolusi 2 px/modul diskalakan ke ~300 px: modul terkecil tetap >= 0,25 mm saat dicetak 100%.
                $png = Code128::png($variant['pattern'], moduleWidth: 2, height: 56);
                $drawings[] = $this->drawing($this->tempFile($png, 'png'), "H{$variant['row']}", 'Barcode '.$variant['code'], height: null, width: 300);
            }
        }

        return $drawings;
    }

    private function drawing(string $path, string $cell, string $name, ?int $height, ?int $width): Drawing
    {
        $drawing = new Drawing;
        $drawing->setName($name);
        $drawing->setPath($path);
        $drawing->setCoordinates($cell);
        $drawing->setOffsetX(4);
        $drawing->setOffsetY(4);
        $height !== null ? $drawing->setHeight($height) : null;
        $width !== null ? $drawing->setWidth($width) : null;

        return $drawing;
    }

    private function tempFile(string $bytes, string $extension): string
    {
        $this->tempDir ??= sys_get_temp_dir().DIRECTORY_SEPARATOR.'boothpos-catalog-'.bin2hex(random_bytes(6));
        if (! is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0700, true);
        }
        $path = $this->tempDir.DIRECTORY_SEPARATOR.bin2hex(random_bytes(6)).'.'.$extension;
        file_put_contents($path, $bytes);

        return $path;
    }

    /** Hapus berkas sementara gambar; dipanggil pemanggil begitu workbook selesai ditulis (juga saat gagal). */
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
