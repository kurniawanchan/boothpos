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
     * URUTAN DEPENDENSI, bukan urutan fisik sheet di dalam berkas.
     * artists/categories harus ada sebelum products bisa mereferensikannya,
     * dan varian harus ada sebelum stok bisa menunjuk SKU-nya. Impor selalu
     * memproses dalam urutan ini berapa pun urutan sheet di berkas.
     */
    public const ORDER = [self::ARTISTS, self::CATEGORIES, self::PRODUCTS, self::STOCK];

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
                'code', 'name', 'parent_code', 'display_order', 'is_active',
            ],
            self::PRODUCTS => [
                'sku', 'artist_code', 'category_code', 'product_segment', 'product_name',
                'description', 'is_preorder', 'preorder_eta', 'product_is_active',
                'variant_name', 'cost_price', 'sell_price', 'low_stock_alert',
                'variant_is_active', 'initial_stock',
            ],
            self::STOCK => [
                'sku', 'current_stock', 'reason',
            ],
            default => throw new \InvalidArgumentException("Sheet tidak dikenali: {$sheet}."),
        };
    }

    /**
     * Satu baris contoh per sheet untuk template. Diisi (bukan template
     * kosong) karena sasaran fitur ini pemilik toko non-teknis: contoh
     * konkret jauh lebih jelas daripada judul kolom saja.
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
            ],
            self::STOCK => [
                'sku' => 'RYUKYSAK0001',
                'current_stock' => 20,
                'reason' => 'Stok opname 1 Oktober',
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
