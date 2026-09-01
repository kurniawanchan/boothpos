<?php

namespace App\Support;

/**
 * Definisi tunggal nama sheet + urutan kolom untuk ekspor .xlsx per
 * entitas, template impor, dan pembacaan berkas impor.
 *
 * Sengaja SATU tempat, bukan tiga daftar terpisah: berkas hasil ekspor
 * harus bisa disunting lalu diimpor kembali. Kalau daftar kolom ekspor
 * dan impor ditulis terpisah, keduanya pasti melenceng cepat atau lambat
 * dan pengguna baru sadar saat impornya gagal.
 *
 * Nama sheet dan nama kolom memakai istilah bahasa Inggris yang sama
 * dengan field API (bukan bahasa Indonesia seperti teks UI), supaya satu
 * berkas yang sama bisa dipetakan langsung ke kontrak API tanpa kamus
 * terjemahan kedua.
 */
final class MasterDataSheets
{
    public const ARTISTS = 'artists';

    public const CATEGORIES = 'categories';

    public const PRODUCTS = 'products';

    public const STOCK = 'stock';

    /**
     * Vendor/bahan baku/BOM — ditambahkan pasca-MVP 2026-09-01 (lihat
     * catatan bertanggal di CLAUDE.md/README.md/PRD). "vendor_prices" dan
     * "bom" mereferensikan vendor/bahan/varian lewat KODE, bukan id
     * database, mengikuti pola persis artist_code/category_code pada sheet
     * 'products' — supaya berkas hasil ekspor tetap portabel antar
     * instalasi dan tidak bergantung pada urutan insert.
     */
    public const VENDORS = 'vendors';

    public const MATERIALS = 'materials';

    public const VENDOR_PRICES = 'vendor_prices';

    public const BOM = 'bom';

    /**
     * URUTAN DEPENDENSI, bukan urutan fisik sheet di dalam berkas.
     * artists/categories harus ada sebelum products bisa mereferensikannya,
     * dan varian harus ada sebelum stok bisa menunjuk SKU-nya. vendors dan
     * materials harus ada sebelum vendor_prices (menunjuk keduanya) dan bom
     * (menunjuk material + SKU varian yang mungkin baru dibuat sheet
     * products). Impor selalu memproses dalam urutan ini berapa pun urutan
     * sheet di berkas.
     */
    public const ORDER = [
        self::ARTISTS, self::CATEGORIES, self::PRODUCTS, self::STOCK,
        self::VENDORS, self::MATERIALS, self::VENDOR_PRICES, self::BOM,
    ];

    /**
     * Kolom per sheet, berurutan. Baris pertama setiap sheet WAJIB berisi
     * judul kolom ini (pembacaan memakai WithHeadingRow).
     */
    public static function headings(string $sheet): array
    {
        return match ($sheet) {
            self::ARTISTS => [
                'code', 'name', 'contact_phone', 'contact_email', 'notes', 'is_active',
            ],
            self::CATEGORIES => [
                'code', 'name', 'parent_code', 'display_order', 'is_active', 'image_filename',
            ],
            self::PRODUCTS => [
                'sku', 'artist_code', 'category_code', 'product_segment', 'product_name',
                'description', 'is_preorder', 'preorder_eta', 'product_is_active',
                'variant_name', 'cost_price', 'sell_price', 'low_stock_alert',
                'variant_is_active', 'initial_stock', 'image_filename',
            ],
            self::STOCK => [
                'sku', 'current_stock', 'reason',
            ],
            self::VENDORS => [
                'code', 'name', 'contact_phone', 'contact_email', 'notes', 'is_active',
            ],
            self::MATERIALS => [
                'code', 'name', 'unit', 'notes', 'is_active',
            ],
            self::VENDOR_PRICES => [
                'vendor_code', 'material_code', 'price', 'is_preferred', 'notes',
            ],
            self::BOM => [
                'sku', 'material_code', 'qty_needed', 'notes',
            ],
            default => throw new \InvalidArgumentException("Sheet tidak dikenali: {$sheet}."),
        };
    }

    /**
     * Satu baris contoh per sheet untuk template. Diisi (bukan template
     * kosong) karena sasaran fitur ini pemilik toko non-teknis: contoh
     * konkret jauh lebih jelas daripada judul kolom saja.
     *
     * Baris-baris contoh ini SALING KONSISTEN dan template apa adanya
     * memang bisa diimpor tanpa galat — sudah dibuktikan lewat
     * MasterDataImportTest::test_the_shipped_template_imports_as_is.
     * Itu bukan kebetulan: SKU pada contoh sheet 'stock' adalah SKU yang
     * PASTI dihasilkan server untuk baris contoh sheet 'products'
     * (RYU + KY + SAK -> RYUKYSAK, varian pertama -> 0001). Kalau contoh
     * di sini diubah, jalankan lagi test itu.
     */
    public static function exampleRow(string $sheet): array
    {
        return match ($sheet) {
            self::ARTISTS => [
                'code' => 'RYU',
                'name' => 'Ryu Illustration',
                'contact_phone' => '',
                'contact_email' => '',
                'notes' => 'Kolom kosong = tidak diubah untuk data yang sudah ada.',
                'is_active' => 1,
            ],
            self::CATEGORIES => [
                'code' => 'KY',
                'name' => 'Keychain',
                'parent_code' => '',
                'display_order' => 1,
                'is_active' => 1,
                'image_filename' => '',
            ],
            self::PRODUCTS => [
                'sku' => '',
                'artist_code' => 'RYU',
                'category_code' => 'KY',
                'product_segment' => 'SAK',
                'product_name' => 'Keychain Sakura',
                'description' => 'Kosongkan sku untuk produk/varian BARU; SKU dibuat server.',
                'is_preorder' => 0,
                'preorder_eta' => '',
                'product_is_active' => 1,
                'variant_name' => 'Standard',
                'cost_price' => '10000.00',
                'sell_price' => '25000.00',
                'low_stock_alert' => 5,
                'variant_is_active' => 1,
                'initial_stock' => 20,
                // Kosong = tidak ada gambar diikutsertakan (Task 6). Diisi
                // dengan nama berkas PERSIS SAMA seperti yang diunggah
                // bersamaan lewat field 'images[]' pada POST
                // /imports/master-data.
                'image_filename' => '',
            ],
            self::STOCK => [
                'sku' => 'RYUKYSAK0001',
                'current_stock' => 20,
                'reason' => 'Isi jumlah AKHIR yang diinginkan, bukan selisih. Contoh alasan: stok opname 1 Oktober.',
            ],
            self::VENDORS => [
                'code' => 'VNAKR',
                'name' => 'Toko Akrilik Jaya',
                'contact_phone' => '',
                'contact_email' => '',
                'notes' => '',
                'is_active' => 1,
            ],
            self::MATERIALS => [
                'code' => 'AC3',
                'name' => 'Acrylic sheet 3mm',
                'unit' => 'lembar',
                'notes' => '',
                'is_active' => 1,
            ],
            self::VENDOR_PRICES => [
                'vendor_code' => 'VNAKR',
                'material_code' => 'AC3',
                'price' => '15000.00',
                'is_preferred' => 1,
                'notes' => '',
            ],
            self::BOM => [
                // SKU sama seperti pada sheet 'stock' — varian ini memang
                // dibuat oleh baris contoh sheet 'products' di atas.
                'sku' => 'RYUKYSAK0001',
                'material_code' => 'AC3',
                'qty_needed' => '1.0000',
                'notes' => '',
            ],
            default => throw new \InvalidArgumentException("Sheet tidak dikenali: {$sheet}."),
        };
    }

    /**
     * Memetakan nama sheet apa adanya dari berkas pengguna ke nama kanonik.
     * Toleran terhadap huruf besar/kecil dan spasi berlebih — Excel
     * gampang sekali menyisakan spasi di nama sheet, dan menolak berkas
     * hanya karena "Products" bukan "products" tidak membantu siapa pun.
     */
    public static function canonicalName(string $rawSheetName): ?string
    {
        $normalized = strtolower(trim($rawSheetName));

        return in_array($normalized, self::ORDER, true) ? $normalized : null;
    }
}
