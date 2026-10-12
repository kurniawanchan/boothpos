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
    public const OUTLETS = 'outlets';

    public const CATEGORIES = 'categories';

    public const PRODUCTS = 'products';

    public const STOCK = 'stock';

    /**
     * Vendor/bahan baku/BOM — ditambahkan pasca-MVP 2026-09-01 (lihat
     * catatan bertanggal di CLAUDE.md/README.md/PRD). "vendor_prices" dan
     * "bom" mereferensikan vendor/bahan/varian lewat KODE, bukan id
     * database, mengikuti pola persis home_outlet_code/category_code pada sheet
     * 'products' — supaya berkas hasil ekspor tetap portabel antar
     * instalasi dan tidak bergantung pada urutan insert.
     */
    public const VENDORS = 'vendors';

    public const MATERIALS = 'materials';

    public const VENDOR_PRICES = 'vendor_prices';

    public const BOM = 'bom';

    /**
     * Role/User — ditambahkan pasca-MVP 2026-09-02 (User Story 4, fitur
     * 001-user-store-settings). Diproses PALING TERAKHIR: 'users'
     * mereferensikan 'roles' lewat NAMA (Role.name adalah kolom unik
     * peran — Role TIDAK punya kolom `code` seperti outlet/kategori/vendor/
     * bahan, jadi mengikuti kunci alami yang sudah ada, bukan menambah
     * kolom baru hanya demi seragam dengan sheet lain).
     */
    /**
     * 045 Stage 3 — menu per outlet. `outlet_items`: item mana dibawa outlet mana (kunci outlet_code + product_code,
     * product_code = `code_prefix` produk). `outlet_prices`: harga khusus varian di sebuah outlet (kunci sku +
     * outlet_code; sel price berisi angka, atau kata `base` untuk menghapus harga khusus = kembali mengikuti dasar).
     * Diproses setelah products/outlets, sebelum roles/users.
     */
    public const OUTLET_ITEMS = 'outlet_items';

    public const OUTLET_PRICES = 'outlet_prices';

    public const ROLES = 'roles';

    public const USERS = 'users';

    /**
     * URUTAN DEPENDENSI, bukan urutan fisik sheet di dalam berkas.
     * artists/categories harus ada sebelum products bisa mereferensikannya,
     * dan varian harus ada sebelum stok bisa menunjuk SKU-nya. vendors dan
     * materials harus ada sebelum vendor_prices (menunjuk keduanya) dan bom
     * (menunjuk material + SKU varian yang mungkin baru dibuat sheet
     * products). roles harus ada sebelum users (menunjuknya lewat nama).
     * Impor selalu memproses dalam urutan ini berapa pun urutan sheet di
     * berkas.
     */
    public const ORDER = [
        self::OUTLETS, self::CATEGORIES, self::PRODUCTS, self::STOCK,
        self::VENDORS, self::MATERIALS, self::VENDOR_PRICES, self::BOM,
        self::OUTLET_ITEMS, self::OUTLET_PRICES,
        self::ROLES, self::USERS,
    ];

    /**
     * Kolom per sheet, berurutan. Baris pertama setiap sheet WAJIB berisi
     * judul kolom ini (pembacaan memakai WithHeadingRow).
     */
    public static function headings(string $sheet): array
    {
        return match ($sheet) {
            self::OUTLETS => [
                'code', 'name', 'contact_phone', 'contact_email', 'notes', 'is_active',
            ],
            self::CATEGORIES => [
                'code', 'name', 'parent_code', 'display_order', 'is_active', 'image_filename',
            ],
            self::PRODUCTS => [
                'sku', 'home_outlet_code', 'category_code', 'product_segment', 'product_name',
                'description', 'product_is_active',
                'variant_name', 'cost_price', 'sell_price', 'low_stock_alert',
                'variant_is_active', 'initial_stock', 'image_filename',
                // Ditambahkan atas permintaan eksplisit pemilik toko — gambar
                // per VARIAN (mis. tiap desain/motif dalam satu produk), di
                // samping image_filename (gambar PRODUK, dipakai bersama
                // semua variannya). Baris manapun boleh membawanya; menunjuk
                // varian baris itu sendiri, bukan seluruh produk.
                'variant_image_filename',
                // 043 — fakta item, DITAMBAHKAN di akhir supaya berkas lama tetap bisa diimpor. `status` hanya EKSPOR
                // (turunan: draft/active/discontinued); status diubah lewat aksi diskontinu/aktifkan, bukan lewat impor.
                'short_name', 'tax_category', 'prep_minutes', 'is_retail', 'status',
            ],
            // 045 Stage 5 — `outlet_code` di AKHIR (berkas lama tetap terbaca): stok adalah saldo per outlet; wajib bila
            // ada beberapa outlet.
            self::STOCK => [
                'sku', 'current_stock', 'reason', 'outlet_code',
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
            self::OUTLET_ITEMS => ['outlet_code', 'product_code', 'is_carried'],
            self::OUTLET_PRICES => ['sku', 'outlet_code', 'price'],
            // menu_keys: daftar kunci menu dipisah koma dalam SATU sel,
            // pola yang sama seperti "comma in a cell" pada kolom lain di
            // kodebase ini (belum ada sebelumnya di sheet manapun, tapi
            // ini satu-satunya cara mewakili daftar di dalam satu sel
            // tanpa membuat satu baris per kombinasi role+menu). Divalidasi
            // terhadap App\Support\MenuKeys::keys() saat apply.
            self::ROLES => [
                'name', 'menu_keys',
            ],
            // role_name menunjuk Role.name (kolom unik peran) — BUKAN kode,
            // karena Role tidak punya kolom code. Sengaja tidak ada kolom
            // password: FR-007 melarang password muncul di manapun pada
            // data massal, baik ekspor maupun impor (lihat docblock kelas
            // MasterDataImportService bagian ROLES/USERS).
            // photo_path ikut diekspor sebagai REFERENSI STRING (path
            // penyimpanan, bukan data biner gambarnya) supaya berkas
            // ekspor lengkap menampilkan foto siapa yang sudah diunggah —
            // tapi diabaikan saat diimpor (foto hanya bisa diubah lewat
            // POST /users/{user}/photo, bukan lewat sel spreadsheet).
            self::USERS => [
                'name', 'username', 'role_name', 'is_active', 'photo_path',
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
     * (RYU + KY + SAK -> RYU-KY-SAK, varian pertama -> 001). Kalau contoh
     * di sini diubah, jalankan lagi test itu.
     */
    public static function exampleRow(string $sheet): array
    {
        return match ($sheet) {
            self::OUTLETS => [
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
                'home_outlet_code' => 'RYU',
                'category_code' => 'KY',
                'product_segment' => 'SAK',
                'product_name' => 'Keychain Sakura',
                'description' => 'Kosongkan sku untuk produk/varian BARU; SKU dibuat server.',
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
                // Gambar VARIAN ini secara spesifik (mis. desain/motif
                // berbeda) — beda dari image_filename di atas, yang gambar
                // PRODUKnya (dipakai bersama semua varian). Sama-sama
                // kosong = tidak ada gambar diikutsertakan.
                'variant_image_filename' => '',
                'short_name' => '',
                'tax_category' => '',
                'prep_minutes' => '',
                'is_retail' => 0,
                'status' => '',
            ],
            self::STOCK => [
                'sku' => 'RYU-KY-SAK-001',
                'current_stock' => 20,
                'reason' => 'Isi jumlah AKHIR yang diinginkan, bukan selisih. Contoh alasan: stok opname 1 Oktober.',
                'outlet_code' => 'RYU',
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
                'sku' => 'RYU-KY-SAK-001',
                'material_code' => 'AC3',
                'qty_needed' => '1.0000',
                'notes' => '',
            ],
            self::OUTLET_ITEMS => [
                'outlet_code' => 'RYU',
                'product_code' => 'RYU-KY-SAK',
                'is_carried' => 1,
            ],
            self::OUTLET_PRICES => [
                'sku' => 'RYU-KY-SAK-001',
                'outlet_code' => 'RYU',
                'price' => '27000.00',
            ],
            self::ROLES => [
                'name' => 'Kasir Cabang Contoh',
                'menu_keys' => 'pos,session',
            ],
            self::USERS => [
                // role_name PERSIS SAMA dengan baris contoh sheet 'roles'
                // di atas — buktinya test_the_shipped_template_imports_as_is
                // (diperluas untuk sheet ini).
                'name' => 'Contoh Kasir',
                'username' => 'contoh.kasir',
                'role_name' => 'Kasir Cabang Contoh',
                'is_active' => 1,
                'photo_path' => '',
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
        $normalized = self::LEGACY_SHEET_NAMES[$normalized] ?? $normalized;

        return in_array($normalized, self::ORDER, true) ? $normalized : null;
    }

    /**
     * Judul kolom lama (sebelum fitur 045: seller → outlet) yang masih
     * diterima SAAT IMPOR, dipetakan ke nama baru. Sengaja hanya di pintu
     * masuk impor: ekspor dan template selalu memakai nama baru, dan
     * berkas lama yang dibawa pemilik toko tetap terbaca. Satu-satunya
     * tempat alias ini didefinisikan.
     */
    public static function legacyColumn(string $sheet, string $column): string
    {
        return self::LEGACY_COLUMNS[$sheet][$column] ?? $column;
    }

    /** Nama sheet lama → nama kanonik. Lihat catatan di legacyColumn(). */
    private const LEGACY_SHEET_NAMES = ['artists' => self::OUTLETS];

    /** Sheet → (judul kolom lama → judul kolom baru). Lihat legacyColumn(). */
    private const LEGACY_COLUMNS = [
        self::PRODUCTS => ['artist_code' => 'home_outlet_code'],
    ];
}
