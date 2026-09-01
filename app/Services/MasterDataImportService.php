<?php

namespace App\Services;

use App\Exceptions\MasterDataImportRowException;
use App\Imports\MasterDataSheetsImport;
use App\Models\Artist;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\LicenseGate;
use App\Support\MasterDataSheets;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Impor massal master data dari SATU berkas .xlsx berisi empat sheet
 * (artists, categories, products, stock) — PRD 7.15 (F15.2/F15.3/F15.8).
 *
 * KEPUTUSAN DESAIN YANG PERLU DIKETAHUI SEBELUM MENGUBAH KELAS INI
 *
 * 1. VALIDASI PENUH DULU, BARU TERAPKAN SEKALIGUS DALAM SATU TRANSAKSI.
 *    Ini menyimpang dari kriteria penerimaan F15.5 di PRD ("simpan 97 baris
 *    valid, laporkan 3 yang gagal"). Alasannya: sheet-sheet ini saling
 *    bergantung (produk menunjuk artist/kategori, stok menunjuk varian).
 *    Menyimpan sebagian berarti bisa berhenti dengan produk yang artistnya
 *    tidak jadi dibuat, atau harga separuh terbarui — kondisi yang jauh
 *    lebih sulit dibereskan pemilik toko daripada sekadar memperbaiki
 *    berkasnya lalu mengulang impor. Risiko "impor merusak data massal"
 *    (PRD 9.6) juga jauh lebih kecil dengan semua-atau-tidak sama sekali.
 *    Kebutuhan pratinjau F15.4 tetap terpenuhi lewat mode dry_run yang
 *    memakai jalur validasi yang sama persis.
 *
 * 2. STOK TIDAK PERNAH DITULIS LANGSUNG. Setiap perubahan stok lewat
 *    StockService::applyMovement() bertipe 'adjustment', supaya
 *    stock_movements tetap jadi riwayat yang jujur (F15.8) dan supaya
 *    cabang activity log F13.4 di dalam applyMovement ikut jalan untuk
 *    setiap penyesuaian yang dilakukan impor.
 *
 * 3. KOLOM STOK BERSIFAT ABSOLUT, BUKAN SELISIH. Angka pada sheet 'stock'
 *    adalah jumlah akhir yang diinginkan; selisih terhadap current_stock
 *    dihitung server lalu diterapkan sebagai satu movement. Ini yang
 *    diharapkan orang saat menyunting spreadsheet ("kolomnya saya isi 20,
 *    berarti stoknya 20"), dan bikin berkas hasil ekspor bisa disunting
 *    lalu diunggah ulang apa adanya.
 *
 * 4. KUNCI PENCOCOKAN (upsert, bukan selalu insert — supaya sheet yang
 *    sudah dikoreksi bisa diunggah ulang tanpa tabrakan unique):
 *      - artists    : kolom unik `code`
 *      - categories : kolom unik `code`
 *      - products   : `sku` bila diisi; bila kosong, produk dicocokkan
 *                     lewat `code_prefix` (= artist_code + category_code +
 *                     product_segment, kolom unik di tabel products) dan
 *                     variannya lewat `variant_name` di bawah produk itu
 *      - stock      : kolom unik `sku`
 *
 * 5. KODE PRODUK TETAP DIHASILKAN SERVER. `code_prefix` lewat
 *    ProductCodeGenerator::buildCodePrefix() dan SKU lewat
 *    nextVariantSku() — kolom `sku` di berkas hanya dipakai untuk MENUNJUK
 *    varian yang sudah ada, tidak pernah untuk menamai varian baru.
 *
 * 6. SEL KOSONG BERARTI "JANGAN DIUBAH", bukan "kosongkan nilainya". Impor
 *    dipakai untuk mengoreksi sebagian kolom (paling sering harga), jadi
 *    default yang aman adalah tidak menghapus data yang tidak disebut.
 *    Menghapus nilai dilakukan lewat layar CRUD biasa.
 */
class MasterDataImportService
{
    /** Baris data pertama: baris 1 selalu judul kolom (WithHeadingRow). */
    private const FIRST_DATA_ROW = 2;

    public const REASON_ADJUSTMENT = 'Impor massal master data';

    public const REASON_INITIAL = 'Impor massal master data (stok awal varian baru)';

    private array $errors = [];

    private array $counts = [];

    public function __construct(
        private ProductCodeGenerator $codeGenerator,
        private StockService $stockService,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @return array{applied: bool, dry_run: bool, sheets: array, ignored_sheets: array, errors: array}
     */
    public function import(string $absolutePath, User $user, bool $dryRun, string $originalName): array
    {
        $this->errors = [];
        $this->counts = [];

        [$sheets, $ignored] = $this->readSheets($absolutePath);

        if ($sheets === []) {
            $this->addError(null, null, null, 'Berkas tidak memuat satu pun sheet yang dikenali ('.implode(', ', MasterDataSheets::ORDER).'). Unduh template impor untuk melihat format yang benar.');

            return $this->result(false, $dryRun, $ignored);
        }

        // Divalidasi mengikuti URUTAN DEPENDENSI, bukan urutan sheet di
        // berkas: kode artist/kategori yang baru diperkenalkan berkas ini
        // harus sudah dikenal sebelum sheet products memeriksanya.
        $plan = [
            MasterDataSheets::ARTISTS => $this->validateArtists($sheets[MasterDataSheets::ARTISTS] ?? []),
            MasterDataSheets::CATEGORIES => $this->validateCategories($sheets[MasterDataSheets::CATEGORIES] ?? []),
        ];
        $plan[MasterDataSheets::PRODUCTS] = $this->validateProducts(
            $sheets[MasterDataSheets::PRODUCTS] ?? [],
            $plan[MasterDataSheets::ARTISTS],
            $plan[MasterDataSheets::CATEGORIES],
        );
        $plan[MasterDataSheets::STOCK] = $this->validateStock($sheets[MasterDataSheets::STOCK] ?? []);

        // Sheet yang tidak dikirim sama sekali tidak dilaporkan — nol baris
        // lebih membingungkan daripada tidak ada entrinya.
        foreach (MasterDataSheets::ORDER as $sheet) {
            if (! array_key_exists($sheet, $sheets)) {
                unset($this->counts[$sheet]);
            }
        }

        if ($this->errors !== [] || $dryRun) {
            return $this->result(false, $dryRun, $ignored);
        }

        try {
            $this->apply($plan, $user, $originalName);
        } catch (MasterDataImportRowException $e) {
            // Transaksi sudah rollback; galat dilaporkan dengan bentuk yang
            // sama seperti galat validasi.
            $this->errors[] = $e->toArray();

            return $this->result(false, $dryRun, $ignored);
        }

        return $this->result(true, $dryRun, $ignored);
    }

    // =================================================================
    // PEMBACAAN BERKAS
    // =================================================================

    /**
     * @return array{0: array<string, array>, 1: string[]}
     */
    private function readSheets(string $absolutePath): array
    {
        $rawNames = (new XlsxReader)->listWorksheetNames($absolutePath);

        $wanted = [];   // nama asli di berkas => nama kanonik
        $ignored = [];

        foreach ($rawNames as $rawName) {
            $canonical = MasterDataSheets::canonicalName($rawName);

            if ($canonical === null || in_array($canonical, $wanted, true)) {
                $ignored[] = $rawName;

                continue;
            }

            $wanted[$rawName] = $canonical;
        }

        if ($wanted === []) {
            return [[], $ignored];
        }

        $raw = Excel::toArray(new MasterDataSheetsImport(array_keys($wanted)), $absolutePath);

        $sheets = [];
        foreach ($wanted as $rawName => $canonical) {
            $rows = $this->normalizeRows($raw[$rawName] ?? []);

            // Sheet dengan judul kolom yang tidak dikenali tidak dilanjutkan
            // ke validasi per baris — kalau tidak, satu kesalahan judul
            // kolom berubah jadi puluhan galat "wajib diisi" yang menutupi
            // masalah sebenarnya.
            $sheets[$canonical] = $this->headingsAreRecognized($canonical, $rows) ? $rows : [];
        }

        return [$sheets, $ignored];
    }

    /**
     * Kolom yang tidak dikenali dibiarkan saja (pemilik toko sering
     * menambah kolom catatan sendiri), TAPI sheet yang judul kolomnya tidak
     * cocok sama sekali dilaporkan satu kali di sini. Tanpa ini, sheet
     * dengan judul kolom bahasa Indonesia akan menghasilkan puluhan galat
     * "wajib diisi" yang tidak menunjuk ke masalah sebenarnya.
     */
    private function headingsAreRecognized(string $sheet, array $rows): bool
    {
        if ($rows === []) {
            return true;
        }

        $known = array_intersect(
            array_keys($rows[0]['values']),
            MasterDataSheets::headings($sheet),
        );

        if ($known !== []) {
            return true;
        }

        $this->addError(
            $sheet,
            1,
            null,
            "Judul kolom pada sheet '{$sheet}' tidak dikenali. Baris pertama harus berisi: "
                .implode(', ', MasterDataSheets::headings($sheet)).'. Unduh template impor untuk format yang benar.'
        );

        return false;
    }

    /**
     * Membuang baris yang seluruh selnya kosong (Excel gemar menyisakan
     * ratusan baris kosong di bawah data) TANPA menggeser nomor baris —
     * nomor baris adalah satu-satunya cara pengguna menemukan kembali
     * baris yang bermasalah di berkasnya.
     */
    private function normalizeRows(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $index => $row) {
            $values = [];

            foreach ((array) $row as $key => $value) {
                if ($key === null || $key === '') {
                    continue;
                }

                $values[(string) $key] = is_string($value) ? trim($value) : $value;
            }

            $allBlank = true;
            foreach ($values as $value) {
                if (! $this->isBlank($value)) {
                    $allBlank = false;
                    break;
                }
            }

            if ($allBlank) {
                continue;
            }

            $normalized[] = ['row' => $index + self::FIRST_DATA_ROW, 'values' => $values];
        }

        return $normalized;
    }

    // =================================================================
    // VALIDASI — ARTISTS
    // =================================================================

    private function validateArtists(array $rows): array
    {
        $sheet = MasterDataSheets::ARTISTS;
        $plan = [];
        $seen = [];
        $created = 0;
        $updated = 0;

        foreach ($rows as $entry) {
            $row = $entry['row'];
            $values = $entry['values'];

            $code = $this->stringValue($values, 'code');

            if ($code === null) {
                $this->addError($sheet, $row, 'code', 'Kode artist wajib diisi.');

                continue;
            }

            $code = strtoupper($code);

            if (strlen($code) !== 3 || ! ctype_alpha($code)) {
                $this->addError($sheet, $row, 'code', "Kode artist '{$code}' harus tepat 3 huruf.");

                continue;
            }

            if (isset($seen[$code])) {
                $this->addError($sheet, $row, 'code', "Kode artist '{$code}' muncul dua kali di sheet ini (baris {$seen[$code]}).");

                continue;
            }
            $seen[$code] = $row;

            $existing = Artist::where('code', $code)->first();

            if ($existing === null && Artist::withTrashed()->where('code', $code)->exists()) {
                $this->addError($sheet, $row, 'code', "Kode artist '{$code}' masih dipakai artist yang sudah dihapus. Pakai kode lain.");

                continue;
            }

            $attributes = [];

            if ($this->filled($values, 'name')) {
                $name = $this->stringValue($values, 'name');
                if (mb_strlen($name) > 100) {
                    $this->addError($sheet, $row, 'name', 'Nama artist maksimal 100 karakter.');
                } else {
                    $attributes['name'] = $name;
                }
            } elseif ($existing === null) {
                $this->addError($sheet, $row, 'name', 'Nama artist wajib diisi untuk artist baru.');
            }

            if ($this->filled($values, 'contact_phone')) {
                $attributes['contact_phone'] = $this->stringValue($values, 'contact_phone');
            }

            if ($this->filled($values, 'contact_email')) {
                $email = $this->stringValue($values, 'contact_email');
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $this->addError($sheet, $row, 'contact_email', "Alamat email '{$email}' tidak valid.");
                } else {
                    $attributes['contact_email'] = $email;
                }
            }

            if ($this->filled($values, 'notes')) {
                $attributes['notes'] = $this->stringValue($values, 'notes');
            }

            if ($this->filled($values, 'is_active')) {
                $isActive = $this->parseBool($sheet, $row, 'is_active', $values['is_active']);
                if ($isActive !== null) {
                    $attributes['is_active'] = $isActive;
                }
            }

            $existing === null ? $created++ : $updated++;

            $plan[] = [
                'row' => $row,
                'code' => $code,
                'attributes' => $attributes,
                'exists' => $existing !== null,
                'is_active' => $attributes['is_active'] ?? ($existing?->is_active ?? true),
            ];
        }

        $this->enforceArtistLicenseQuota($plan);

        $this->counts[$sheet] = ['rows' => count($rows), 'created' => $created, 'updated' => $updated, 'unchanged' => 0];

        return $plan;
    }

    /**
     * Gerbang lisensi Pro/Master TIDAK boleh bisa dilewati lewat impor.
     * ArtistPolicy::create menegakkannya untuk POST /artists; endpoint impor
     * tidak lewat policy itu, jadi kuotanya diperiksa eksplisit di sini —
     * kalau tidak, satu berkas .xlsx berisi 20 artist jadi jalan pintas
     * mengubah instalasi Pro menjadi Master secara gratis.
     */
    private function enforceArtistLicenseQuota(array $plan): void
    {
        if ($plan === [] || LicenseGate::multiArtistEnabled()) {
            return;
        }

        $activeCodes = Artist::where('is_active', true)->pluck('code')->map(fn ($c) => strtoupper($c))->all();
        $projected = array_flip($activeCodes);

        foreach ($plan as $entry) {
            if ($entry['is_active']) {
                $projected[$entry['code']] = true;
            } else {
                unset($projected[$entry['code']]);
            }
        }

        // Dua jalan keluar, bukan satu: (a) hasilnya masih dalam kuota, atau
        // (b) instalasi ini MEMANG sudah melebihi kuota sebelum impor
        // (mis. lisensi diturunkan dari Master ke Pro) dan berkas ini tidak
        // memperburuknya. Menolak impor yang tidak menambah artist aktif
        // hanya akan mengunci pemilik toko dari mengoreksi datanya sendiri.
        if (count($projected) <= 1 || count($projected) <= count($activeCodes)) {
            return;
        }

        $firstRow = $plan[0]['row'] ?? self::FIRST_DATA_ROW;

        $this->addError(
            MasterDataSheets::ARTISTS,
            $firstRow,
            'is_active',
            'Instalasi ini memakai lisensi Pro (satu artist aktif). Berkas ini akan menghasilkan '
                .count($projected).' artist aktif. Upgrade ke Master untuk menambah artist lain.'
        );
    }

    // =================================================================
    // VALIDASI — CATEGORIES
    // =================================================================

    private function validateCategories(array $rows): array
    {
        $sheet = MasterDataSheets::CATEGORIES;
        $plan = [];
        $seen = [];
        $created = 0;
        $updated = 0;

        foreach ($rows as $entry) {
            $row = $entry['row'];
            $values = $entry['values'];

            $code = $this->stringValue($values, 'code');

            if ($code === null) {
                $this->addError($sheet, $row, 'code', 'Kode kategori wajib diisi.');

                continue;
            }

            $code = strtoupper($code);

            if (strlen($code) !== 2 || ! ctype_alpha($code)) {
                $this->addError($sheet, $row, 'code', "Kode kategori '{$code}' harus tepat 2 huruf.");

                continue;
            }

            if (isset($seen[$code])) {
                $this->addError($sheet, $row, 'code', "Kode kategori '{$code}' muncul dua kali di sheet ini (baris {$seen[$code]}).");

                continue;
            }
            $seen[$code] = $row;

            $existing = Category::where('code', $code)->first();

            if ($existing === null && Category::withTrashed()->where('code', $code)->exists()) {
                $this->addError($sheet, $row, 'code', "Kode kategori '{$code}' masih dipakai kategori yang sudah dihapus. Pakai kode lain.");

                continue;
            }

            $attributes = [];

            if ($this->filled($values, 'name')) {
                $name = $this->stringValue($values, 'name');
                if (mb_strlen($name) > 100) {
                    $this->addError($sheet, $row, 'name', 'Nama kategori maksimal 100 karakter.');
                } else {
                    $attributes['name'] = $name;
                }
            } elseif ($existing === null) {
                $this->addError($sheet, $row, 'name', 'Nama kategori wajib diisi untuk kategori baru.');
            }

            if ($this->filled($values, 'display_order')) {
                $order = $this->parseInt($sheet, $row, 'display_order', $values['display_order']);
                if ($order !== null) {
                    $attributes['display_order'] = $order;
                }
            }

            if ($this->filled($values, 'is_active')) {
                $isActive = $this->parseBool($sheet, $row, 'is_active', $values['is_active']);
                if ($isActive !== null) {
                    $attributes['is_active'] = $isActive;
                }
            }

            $parentCode = null;
            if ($this->filled($values, 'parent_code')) {
                $parentCode = strtoupper($this->stringValue($values, 'parent_code'));

                if ($parentCode === $code) {
                    $this->addError($sheet, $row, 'parent_code', 'Kategori tidak boleh menjadi induk dirinya sendiri.');
                    $parentCode = null;
                }
            }

            $existing === null ? $created++ : $updated++;

            $plan[] = [
                'row' => $row,
                'code' => $code,
                'attributes' => $attributes,
                'parent_code' => $parentCode,
                'exists' => $existing !== null,
            ];
        }

        // parent_code diperiksa setelah seluruh baris terbaca, supaya induk
        // yang didefinisikan di bawah anaknya di dalam berkas tetap sah.
        $codesInFile = array_column($plan, 'code');

        foreach ($plan as $entry) {
            if ($entry['parent_code'] === null) {
                continue;
            }

            $known = in_array($entry['parent_code'], $codesInFile, true)
                || Category::where('code', $entry['parent_code'])->exists();

            if (! $known) {
                $this->addError($sheet, $entry['row'], 'parent_code', "Kategori induk dengan kode '{$entry['parent_code']}' tidak ditemukan.");
            }
        }

        $this->counts[$sheet] = ['rows' => count($rows), 'created' => $created, 'updated' => $updated, 'unchanged' => 0];

        return $plan;
    }

    // =================================================================
    // VALIDASI — PRODUCTS (satu baris = satu VARIAN)
    // =================================================================

    private function validateProducts(array $rows, array $artistPlan, array $categoryPlan): array
    {
        $sheet = MasterDataSheets::PRODUCTS;
        $plan = [];
        $seenSku = [];
        $seenVariantKey = [];
        $created = 0;
        $updated = 0;

        $artistCodesInFile = array_column($artistPlan, 'code');
        $categoryCodesInFile = array_column($categoryPlan, 'code');

        foreach ($rows as $entry) {
            $row = $entry['row'];
            $values = $entry['values'];

            $sku = $this->filled($values, 'sku') ? strtoupper($this->stringValue($values, 'sku')) : null;

            $variantAttributes = $this->readVariantColumns($sheet, $row, $values);
            $productAttributes = $this->readProductColumns($sheet, $row, $values);

            // ---------------------------------------------------------
            // Jalur A — baris menunjuk varian yang SUDAH ADA lewat SKU.
            // ---------------------------------------------------------
            if ($sku !== null) {
                if (isset($seenSku[$sku])) {
                    $this->addError($sheet, $row, 'sku', "SKU '{$sku}' muncul dua kali di sheet ini (baris {$seenSku[$sku]}).");

                    continue;
                }
                $seenSku[$sku] = $row;

                $variant = ProductVariant::with('product.artist', 'product.category')->where('sku', $sku)->first();

                if ($variant === null) {
                    $this->addError($sheet, $row, 'sku', "SKU '{$sku}' tidak ditemukan. Kosongkan kolom sku untuk membuat varian baru — SKU dibuat otomatis oleh sistem.");

                    continue;
                }

                // Kode artist/kategori/segmen membentuk code_prefix yang
                // permanen (F19.4), jadi impor tidak boleh memindahkan
                // produk ke artist lain diam-diam. Ketidakcocokan
                // dilaporkan, bukan diabaikan.
                foreach ([
                    'artist_code' => $variant->product->artist->code,
                    'category_code' => $variant->product->category->code,
                    'product_segment' => $variant->product->product_segment,
                ] as $column => $actual) {
                    if ($this->filled($values, $column) && strtoupper($this->stringValue($values, $column)) !== strtoupper((string) $actual)) {
                        $this->addError($sheet, $row, $column, "SKU '{$sku}' terikat pada {$column} '{$actual}'. Kode produk bersifat permanen dan tidak bisa dipindahkan lewat impor.");
                    }
                }

                if ($this->filled($values, 'initial_stock')) {
                    $this->addError($sheet, $row, 'initial_stock', "initial_stock hanya untuk varian BARU. Ubah stok SKU '{$sku}' lewat sheet 'stock'.");
                }

                $updated++;

                $plan[] = [
                    'row' => $row,
                    'mode' => 'variant_by_sku',
                    'sku' => $sku,
                    'product_attributes' => $productAttributes,
                    'variant_attributes' => $variantAttributes,
                ];

                continue;
            }

            // ---------------------------------------------------------
            // Jalur B — baris mendefinisikan produk/varian lewat kode.
            // ---------------------------------------------------------
            $artistCode = $this->filled($values, 'artist_code') ? strtoupper($this->stringValue($values, 'artist_code')) : null;
            $categoryCode = $this->filled($values, 'category_code') ? strtoupper($this->stringValue($values, 'category_code')) : null;
            $productName = $this->stringValue($values, 'product_name');
            $variantName = $this->stringValue($values, 'variant_name');

            $rowIsBroken = false;

            if ($artistCode === null) {
                $this->addError($sheet, $row, 'artist_code', 'Kode artist wajib diisi bila kolom sku dikosongkan.');
                $rowIsBroken = true;
            } elseif (! in_array($artistCode, $artistCodesInFile, true) && ! Artist::where('code', $artistCode)->exists()) {
                $this->addError($sheet, $row, 'artist_code', "Artist dengan kode '{$artistCode}' tidak ditemukan.");
                $rowIsBroken = true;
            }

            if ($categoryCode === null) {
                $this->addError($sheet, $row, 'category_code', 'Kode kategori wajib diisi bila kolom sku dikosongkan.');
                $rowIsBroken = true;
            } elseif (! in_array($categoryCode, $categoryCodesInFile, true) && ! Category::where('code', $categoryCode)->exists()) {
                $this->addError($sheet, $row, 'category_code', "Kategori dengan kode '{$categoryCode}' tidak ditemukan.");
                $rowIsBroken = true;
            }

            if ($productName === null) {
                $this->addError($sheet, $row, 'product_name', 'Nama produk wajib diisi bila kolom sku dikosongkan.');
                $rowIsBroken = true;
            }

            if ($variantName === null) {
                $this->addError($sheet, $row, 'variant_name', 'Nama varian wajib diisi bila kolom sku dikosongkan.');
                $rowIsBroken = true;
            }

            $segment = null;
            if ($this->filled($values, 'product_segment')) {
                $segment = strtoupper($this->stringValue($values, 'product_segment'));
                if (strlen($segment) !== 3 || ! ctype_alpha($segment)) {
                    $this->addError($sheet, $row, 'product_segment', "Segmen produk '{$segment}' harus tepat 3 huruf.");
                    $rowIsBroken = true;
                }
            } elseif ($productName !== null) {
                // Jalur penurunan yang sama dengan POST /products (F19.8).
                $segment = $this->codeGenerator->deriveSegmentFromName($productName);
            }

            if ($rowIsBroken || $segment === null) {
                continue;
            }

            $codePrefix = $artistCode.$categoryCode.$segment;
            $variantKey = $codePrefix.'|'.mb_strtolower($variantName);

            if (isset($seenVariantKey[$variantKey])) {
                $this->addError($sheet, $row, 'variant_name', "Varian '{$variantName}' untuk kode produk {$codePrefix} muncul dua kali di sheet ini (baris {$seenVariantKey[$variantKey]}).");

                continue;
            }
            $seenVariantKey[$variantKey] = $row;

            $product = Product::where('code_prefix', $codePrefix)->first();

            if ($product === null && Product::withTrashed()->where('code_prefix', $codePrefix)->exists()) {
                $this->addError($sheet, $row, 'product_segment', "Kode produk {$codePrefix} masih dipakai produk yang sudah dihapus. Ubah product_segment secara manual.");

                continue;
            }

            $variant = null;
            if ($product !== null) {
                $matches = ProductVariant::where('product_id', $product->id)
                    ->where('variant_name', $variantName)
                    ->orderBy('id')
                    ->get();

                if ($matches->count() > 1) {
                    $this->addError($sheet, $row, 'variant_name', "Produk {$codePrefix} punya lebih dari satu varian bernama '{$variantName}'. Isi kolom sku untuk menunjuk varian yang dimaksud.");

                    continue;
                }

                $variant = $matches->first();
            }

            $isNewVariant = $variant === null;

            if ($isNewVariant && ! array_key_exists('sell_price', $variantAttributes)) {
                $this->addError($sheet, $row, 'sell_price', 'Harga jual wajib diisi untuk varian baru.');

                continue;
            }

            // Cermin aturan StoreProductRequest: preorder wajib punya ETA.
            if ($product === null) {
                $isPreorder = $productAttributes['is_preorder'] ?? false;
                $hasEta = array_key_exists('preorder_eta', $productAttributes);

                if ($isPreorder && ! $hasEta) {
                    $this->addError($sheet, $row, 'preorder_eta', 'Produk pre-order wajib punya perkiraan tanggal tiba (preorder_eta).');

                    continue;
                }
            }

            $initialStock = null;
            if ($this->filled($values, 'initial_stock')) {
                if (! $isNewVariant) {
                    $this->addError($sheet, $row, 'initial_stock', "initial_stock hanya untuk varian BARU. Varian '{$variantName}' sudah ada — ubah stoknya lewat sheet 'stock'.");
                } else {
                    $initialStock = $this->parseInt($sheet, $row, 'initial_stock', $values['initial_stock'], min: 0);
                }
            }

            $isNewVariant ? $created++ : $updated++;

            $plan[] = [
                'row' => $row,
                'mode' => 'variant_by_code',
                'artist_code' => $artistCode,
                'category_code' => $categoryCode,
                'segment' => $segment,
                'code_prefix' => $codePrefix,
                'product_name' => $productName,
                'variant_name' => $variantName,
                'product_attributes' => $productAttributes,
                'variant_attributes' => $variantAttributes,
                'initial_stock' => $initialStock,
                'product_exists' => $product !== null,
            ];
        }

        $this->counts[$sheet] = ['rows' => count($rows), 'created' => $created, 'updated' => $updated, 'unchanged' => 0];

        return $plan;
    }

    private function readProductColumns(string $sheet, int $row, array $values): array
    {
        $attributes = [];

        if ($this->filled($values, 'product_name')) {
            $name = $this->stringValue($values, 'product_name');
            if (mb_strlen($name) > 150) {
                $this->addError($sheet, $row, 'product_name', 'Nama produk maksimal 150 karakter.');
            } else {
                $attributes['name'] = $name;
            }
        }

        if ($this->filled($values, 'description')) {
            $attributes['description'] = $this->stringValue($values, 'description');
        }

        if ($this->filled($values, 'is_preorder')) {
            $isPreorder = $this->parseBool($sheet, $row, 'is_preorder', $values['is_preorder']);
            if ($isPreorder !== null) {
                $attributes['is_preorder'] = $isPreorder;
            }
        }

        if ($this->filled($values, 'preorder_eta')) {
            $eta = $this->parseDate($sheet, $row, 'preorder_eta', $values['preorder_eta']);
            if ($eta !== null) {
                $attributes['preorder_eta'] = $eta;
            }
        }

        if ($this->filled($values, 'product_is_active')) {
            $isActive = $this->parseBool($sheet, $row, 'product_is_active', $values['product_is_active']);
            if ($isActive !== null) {
                $attributes['is_active'] = $isActive;
            }
        }

        return $attributes;
    }

    private function readVariantColumns(string $sheet, int $row, array $values): array
    {
        $attributes = [];

        if ($this->filled($values, 'variant_name')) {
            $name = $this->stringValue($values, 'variant_name');
            if (mb_strlen($name) > 100) {
                $this->addError($sheet, $row, 'variant_name', 'Nama varian maksimal 100 karakter.');
            } else {
                $attributes['variant_name'] = $name;
            }
        }

        foreach (['cost_price', 'sell_price'] as $column) {
            if ($this->filled($values, $column)) {
                $price = $this->parseDecimal($sheet, $row, $column, $values[$column]);
                if ($price !== null) {
                    $attributes[$column] = $price;
                }
            }
        }

        if ($this->filled($values, 'low_stock_alert')) {
            $alert = $this->parseInt($sheet, $row, 'low_stock_alert', $values['low_stock_alert'], min: 0);
            if ($alert !== null) {
                $attributes['low_stock_alert'] = $alert;
            }
        }

        if ($this->filled($values, 'variant_is_active')) {
            $isActive = $this->parseBool($sheet, $row, 'variant_is_active', $values['variant_is_active']);
            if ($isActive !== null) {
                $attributes['is_active'] = $isActive;
            }
        }

        return $attributes;
    }

    // =================================================================
    // VALIDASI — STOCK
    // =================================================================

    private function validateStock(array $rows): array
    {
        $sheet = MasterDataSheets::STOCK;
        $plan = [];
        $seen = [];
        $updated = 0;
        $unchanged = 0;

        foreach ($rows as $entry) {
            $row = $entry['row'];
            $values = $entry['values'];

            $sku = $this->filled($values, 'sku') ? strtoupper($this->stringValue($values, 'sku')) : null;

            if ($sku === null) {
                $this->addError($sheet, $row, 'sku', 'SKU wajib diisi.');

                continue;
            }

            if (isset($seen[$sku])) {
                $this->addError($sheet, $row, 'sku', "SKU '{$sku}' muncul dua kali di sheet ini (baris {$seen[$sku]}).");

                continue;
            }
            $seen[$sku] = $row;

            $variant = ProductVariant::where('sku', $sku)->first();

            if ($variant === null) {
                // SKU varian baru dibuat SERVER, jadi tidak mungkin ditulis
                // pengguna di berkas yang sama. Untuk varian baru, isi
                // kolom initial_stock di sheet 'products'.
                $this->addError($sheet, $row, 'sku', "SKU '{$sku}' tidak ditemukan. Untuk varian baru, isi kolom initial_stock di sheet 'products' — SKU dibuat otomatis oleh sistem.");

                continue;
            }

            if (! $this->filled($values, 'current_stock')) {
                $this->addError($sheet, $row, 'current_stock', 'Jumlah stok wajib diisi.');

                continue;
            }

            $target = $this->parseInt($sheet, $row, 'current_stock', $values['current_stock'], min: 0);

            if ($target === null) {
                continue;
            }

            $delta = $target - $variant->current_stock;

            $reason = $this->stringValue($values, 'reason');

            // stock_movements.reason hanya 255 karakter, dan sebagian sudah
            // terpakai prefiks "Impor massal master data — ".
            if ($reason !== null && mb_strlen($reason) > 200) {
                $this->addError($sheet, $row, 'reason', 'Alasan maksimal 200 karakter.');

                continue;
            }

            $delta === 0 ? $unchanged++ : $updated++;

            $plan[] = [
                'row' => $row,
                'sku' => $sku,
                'target' => $target,
                'reason' => $reason,
            ];
        }

        $this->counts[$sheet] = ['rows' => count($rows), 'created' => 0, 'updated' => $updated, 'unchanged' => $unchanged];

        return $plan;
    }

    // =================================================================
    // PENERAPAN — satu transaksi, semua-atau-tidak sama sekali
    // =================================================================

    private function apply(array $plan, User $user, string $originalName): void
    {
        DB::transaction(function () use ($plan, $user, $originalName) {
            $this->applyArtists($plan[MasterDataSheets::ARTISTS]);
            $this->applyCategories($plan[MasterDataSheets::CATEGORIES]);
            $this->applyProducts($plan[MasterDataSheets::PRODUCTS], $user);
            $this->applyStock($plan[MasterDataSheets::STOCK], $user);

            // F13.4 — impor massal adalah tindakan sensitif, dan ditulis DI
            // DALAM transaksi yang sama seperti seluruh mutasi lain di
            // kodebase ini: kalau impornya batal, lognya ikut batal.
            // Setiap penyesuaian stok juga menulis lognya sendiri lewat
            // StockService::applyMovement().
            $this->activityLogger->log(
                userId: $user->id,
                action: 'imported',
                entityType: 'MasterDataImport',
                entityId: null,
                description: "Impor massal master data dari berkas {$originalName}.",
                newValues: $this->counts,
            );
        });
    }

    private function applyArtists(array $plan): void
    {
        foreach ($plan as $entry) {
            $artist = Artist::firstOrNew(['code' => $entry['code']]);
            $artist->fill($entry['attributes']);
            $artist->code = $entry['code'];
            $artist->save();
        }
    }

    private function applyCategories(array $plan): void
    {
        // Dua lintasan: seluruh kategori disimpan dulu tanpa induk, baru
        // induknya dipasang. Tanpa ini, induk yang didefinisikan di baris
        // BAWAH anaknya belum punya id saat dibutuhkan.
        foreach ($plan as $entry) {
            $category = Category::firstOrNew(['code' => $entry['code']]);
            $category->fill($entry['attributes']);
            $category->code = $entry['code'];
            $category->save();
        }

        foreach ($plan as $entry) {
            if ($entry['parent_code'] === null) {
                continue;
            }

            $category = Category::where('code', $entry['code'])->firstOrFail();
            $parent = Category::where('code', $entry['parent_code'])->firstOrFail();

            if ($category->parent_id === $parent->id) {
                continue;
            }

            // Siklus baru bisa diperiksa di sini, setelah seluruh baris
            // tersimpan — memakai helper yang sama dengan endpoint CRUD
            // (Category::wouldCreateCycle), bukan aturan tandingan.
            if (Category::wouldCreateCycle($category->id, $parent->id)) {
                throw new MasterDataImportRowException(
                    MasterDataSheets::CATEGORIES,
                    $entry['row'],
                    'parent_code',
                    "Menjadikan '{$entry['parent_code']}' sebagai induk '{$entry['code']}' membentuk siklus kategori."
                );
            }

            $category->parent_id = $parent->id;
            $category->save();
        }
    }

    private function applyProducts(array $plan, User $user): void
    {
        foreach ($plan as $entry) {
            if ($entry['mode'] === 'variant_by_sku') {
                $variant = ProductVariant::with('product')->where('sku', $entry['sku'])->firstOrFail();

                if ($entry['product_attributes'] !== []) {
                    $variant->product->fill($entry['product_attributes'])->save();
                }

                if ($entry['variant_attributes'] !== []) {
                    $variant->fill($entry['variant_attributes'])->save();
                }

                continue;
            }

            $product = Product::where('code_prefix', $entry['code_prefix'])->first();

            if ($product === null) {
                $artist = Artist::where('code', $entry['artist_code'])->firstOrFail();
                $category = Category::where('code', $entry['category_code'])->firstOrFail();

                // Jalur pembuatan kode yang sama persis dengan POST /products
                // — bukan menyusun code_prefix sendiri di sini.
                $codePrefix = $this->codeGenerator->buildCodePrefix($artist->code, $category->code, $entry['segment']);

                $product = Product::create(array_merge([
                    'artist_id' => $artist->id,
                    'category_id' => $category->id,
                    'code_prefix' => $codePrefix,
                    'product_segment' => $entry['segment'],
                    'name' => $entry['product_name'],
                ], $entry['product_attributes']));
            } elseif ($entry['product_attributes'] !== []) {
                $product->fill($entry['product_attributes'])->save();
            }

            $variant = ProductVariant::where('product_id', $product->id)
                ->where('variant_name', $entry['variant_name'])
                ->orderBy('id')
                ->first();

            if ($variant === null) {
                $variant = $product->variants()->create(array_merge([
                    'sku' => $this->codeGenerator->nextVariantSku($product),
                    'variant_name' => $entry['variant_name'],
                    'cost_price' => 0,
                ], $entry['variant_attributes']));

                if (($entry['initial_stock'] ?? 0) > 0) {
                    // Stok awal pun lewat applyMovement, bukan menulis
                    // current_stock langsung — supaya stock_movements tetap
                    // memuat asal-usul setiap unit (F15.8).
                    $this->stockService->applyMovement(
                        variant: $variant,
                        type: 'adjustment',
                        qtyChange: $entry['initial_stock'],
                        referenceType: 'MasterDataImport',
                        reason: self::REASON_INITIAL,
                        userId: $user->id,
                    );
                }

                continue;
            }

            if ($entry['variant_attributes'] !== []) {
                $variant->fill($entry['variant_attributes'])->save();
            }
        }
    }

    private function applyStock(array $plan, User $user): void
    {
        foreach ($plan as $entry) {
            $variant = ProductVariant::where('sku', $entry['sku'])->firstOrFail();

            $delta = $entry['target'] - $variant->current_stock;

            // applyMovement menolak qty_change nol (dan memang benar
            // begitu — movement nol bukan riwayat, cuma derau).
            if ($delta === 0) {
                continue;
            }

            $this->stockService->applyMovement(
                variant: $variant,
                type: 'adjustment',
                qtyChange: $delta,
                referenceType: 'MasterDataImport',
                reason: self::REASON_ADJUSTMENT.($entry['reason'] !== null ? ' — '.$entry['reason'] : ''),
                userId: $user->id,
            );
        }
    }

    // =================================================================
    // UTILITAS NILAI SEL
    // =================================================================

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function filled(array $values, string $key): bool
    {
        return array_key_exists($key, $values) && ! $this->isBlank($values[$key]);
    }

    private function stringValue(array $values, string $key): ?string
    {
        if (! $this->filled($values, $key)) {
            return null;
        }

        return trim((string) $values[$key]);
    }

    private function parseBool(string $sheet, int $row, string $column, mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        if (in_array($normalized, ['1', 'true', 'ya', 'y', 'yes', 'aktif', 'active'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'tidak', 'no', 'n', 'nonaktif', 'inactive'], true)) {
            return false;
        }

        $this->addError($sheet, $row, $column, "Nilai '{$value}' tidak dikenali. Isi 1/0 atau ya/tidak.");

        return null;
    }

    private function parseInt(string $sheet, int $row, string $column, mixed $value, ?int $min = null): ?int
    {
        if (! is_numeric($value) || (float) $value != floor((float) $value)) {
            $this->addError($sheet, $row, $column, "Nilai '{$value}' harus berupa bilangan bulat.");

            return null;
        }

        $parsed = (int) $value;

        if ($min !== null && $parsed < $min) {
            $this->addError($sheet, $row, $column, "Nilai '{$value}' tidak boleh kurang dari {$min}.");

            return null;
        }

        return $parsed;
    }

    private function parseDecimal(string $sheet, int $row, string $column, mixed $value): ?float
    {
        // Sengaja TIDAK menebak pemisah ribuan gaya Indonesia: "25.000"
        // bisa berarti 25000 atau 25,0 dan menebaknya pada kolom UANG
        // adalah cara termurah merusak data harga sepanjang satu berkas.
        if (! is_numeric($value)) {
            $this->addError($sheet, $row, $column, "Nilai '{$value}' harus berupa angka polos tanpa pemisah ribuan (contoh: 25000 atau 25000.00).");

            return null;
        }

        $parsed = (float) $value;

        if ($parsed < 0) {
            $this->addError($sheet, $row, $column, 'Nilai tidak boleh negatif.');

            return null;
        }

        // Kolom harga adalah decimal(14,2) — nilai di atas ini akan ditolak
        // MySQL dengan galat 500 yang tidak bisa dibaca pemilik toko.
        if ($parsed > 999999999999.99) {
            $this->addError($sheet, $row, $column, 'Nilai terlalu besar.');

            return null;
        }

        return $parsed;
    }

    private function parseDate(string $sheet, int $row, string $column, mixed $value): ?string
    {
        // Sel bertipe tanggal di Excel sampai ke sini sebagai serial number,
        // bukan teks — keduanya harus diterima.
        if (is_numeric($value)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        $input = trim((string) $value);

        try {
            $date = Carbon::createFromFormat('Y-m-d', $input);
        } catch (\Throwable) {
            $date = false;
        }

        // Perbandingan balik WAJIB: createFromFormat menerima '2026-13-45'
        // lalu diam-diam menggulungnya jadi 2027-02-14, bukan menolaknya.
        if (! $date || $date->toDateString() !== $input) {
            $this->addError($sheet, $row, $column, "Tanggal '{$value}' harus dalam format YYYY-MM-DD.");

            return null;
        }

        return $date->toDateString();
    }

    // =================================================================
    // HASIL
    // =================================================================

    private function addError(?string $sheet, ?int $row, ?string $column, string $message): void
    {
        $this->errors[] = [
            'sheet' => $sheet,
            'row' => $row,
            'column' => $column,
            'message' => $message,
        ];
    }

    private function result(bool $applied, bool $dryRun, array $ignored): array
    {
        // Urutan sheet pada hasil dikunci ke urutan dependensi supaya UI
        // menampilkannya dalam urutan yang sama dengan pemrosesannya.
        $sheets = [];
        foreach (MasterDataSheets::ORDER as $sheet) {
            if (isset($this->counts[$sheet])) {
                $sheets[$sheet] = $this->counts[$sheet];
            }
        }

        return [
            'applied' => $applied,
            'dry_run' => $dryRun,
            'sheets' => $sheets,
            'ignored_sheets' => array_values($ignored),
            'errors' => $this->errors,
        ];
    }
}
