<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Rules\OutletCodeRule;
use Illuminate\Validation\ValidationException;

/**
 * Menghasilkan code_prefix (10–12 karakter) dan SKU varian (14–16 karakter),
 * format dipisah tanda hubung atas permintaan pengguna (sebelumnya
 * digabung tanpa pemisah — lihat migration
 * 2026_10_25_000001_widen_product_code_and_sku_columns_for_dashed_format
 * untuk riwayat perubahan lebar kolom):
 *   code_prefix = outlet.code(3–5) + "-" + category.code(2) + "-" + product_segment(3)
 *   sku         = code_prefix + "-" + urutan 3 digit
 *
 * Kode produk/SKU bersifat PERMANEN setelah dibuat (F19.4) — service ini hanya
 * dipanggil sekali saat entitas dibuat, tidak pernah untuk regenerasi. Sejak 079
 * KODE OUTLET boleh disunting, tapi itu tidak menulis ulang kode yang sudah ada;
 * hanya produk yang dibuat sesudahnya memakai kode outlet yang baru.
 * Kode/SKU yang sudah ada dari SEBELUM perubahan format ini tetap dalam
 * bentuk lama (tanpa tanda hubung) — tidak pernah diregenerasi ulang.
 */
class ProductCodeGenerator
{
    /**
     * Menurunkan segmen 3 huruf dari nama produk bila tidak diberikan
     * secara manual (F19.8). Non-huruf dibuang, hasil selalu tepat 3
     * karakter (dipad dengan 'X' bila nama terlalu pendek).
     */
    public function deriveSegmentFromName(string $name): string
    {
        $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', $name));
        $segment = substr($letters, 0, 3);

        return str_pad($segment, 3, 'X');
    }

    /**
     * Menyusun code_prefix (format "ART-CA-SEG", dipisah tanda hubung).
     * TIDAK mencoba "pintar" menghindari tabrakan dengan mengubah-ubah
     * segmen secara otomatis — bila tabrakan terjadi, lempar exception
     * agar admin diminta menyunting product_segment secara manual
     * (F19.5), sesuai KISS: jangan menebak niat pengguna.
     */
    public function buildCodePrefix(string $outletCode, string $categoryCode, string $productSegment): string
    {
        $prefix = $this->formatPrefix($outletCode, $categoryCode, $productSegment);

        if ($this->prefixTaken($prefix)) {
            throw ValidationException::withMessages([
                'product_segment' => "Kombinasi kode ini ({$prefix}) sudah dipakai produk lain. Ubah segmen nama produk secara manual.",
            ]);
        }

        return $prefix;
    }

    /**
     * 068 (research D4) — untuk pengisian katalog massal, tempat admin tidak ada untuk menyunting segmen satu
     * per satu (F19.5 tetap berlaku untuk layar produk lewat buildCodePrefix()). Mencoba kandidat
     * segmentCandidates() berurutan dan mengembalikan [segmen, code_prefix] pertama yang belum dipakai di
     * mode mana pun. Deterministik: nama dan isi DB yang sama selalu menghasilkan kode yang sama.
     *
     * @return array{0: string, 1: string}
     */
    public function firstFreeCodePrefix(string $outletCode, string $categoryCode, string $name): array
    {
        foreach ($this->segmentCandidates($name) as $segment) {
            $prefix = $this->formatPrefix($outletCode, $categoryCode, $segment);
            if (! $this->prefixTaken($prefix)) {
                return [$segment, $prefix];
            }
        }

        throw new \RuntimeException("Tidak ada segmen kode yang bebas untuk '{$name}'.");
    }

    /**
     * 068 (research D4) — kandidat segmen 3 huruf, berurutan:
     *  1. inisial tiga kata pertama; nama < 3 kata diisi huruf berikutnya dari kata terakhir ("Dimsum Isi 4" → DIS);
     *  2. huruf pertama kata pertama + dua huruf pertama kata terakhir ("Roti Bakar Coklat" → RCO);
     *  3. tiga huruf pertama nama (= deriveSegmentFromName());
     *  4. dua huruf pertama + A…Z.
     * Kata = deret huruf; angka dan simbol dibuang. Kandidat ganda dibuang dengan urutan tetap.
     *
     * @return list<string>
     */
    public function segmentCandidates(string $name): array
    {
        $words = $this->letterWords($name) ?: ['XXX'];
        $last = $words[count($words) - 1];
        $letters = implode('', $words);

        $candidates = [
            count($words) >= 3
                ? $words[0][0].$words[1][0].$words[2][0]
                : $this->pad(implode('', array_map(fn (string $w) => $w[0], $words)).substr($last, 1), 3),
            $this->pad($words[0][0].substr($last, 0, 2), 3),
            $this->deriveSegmentFromName($name),
        ];
        foreach (range('A', 'Z') as $letter) {
            $candidates[] = $this->pad(substr($letters, 0, 2), 2).$letter;
        }

        return array_values(array_unique($candidates));
    }

    /**
     * 068 (research D4) — kode kategori 2 karakter pertama yang bebas. `categories.code` UNIQUE di seluruh
     * tabel (bukan per data_mode), jadi dicek lintas mode dan termasuk kategori terhapus. Kandidat: huruf
     * pertama dua kata pertama (satu kata → dua huruf pertamanya), lalu huruf pertama + 1…9, huruf pertama +
     * A…Z, lalu AA…Z9.
     */
    public function freeCategoryCode(string $name): string
    {
        $taken = Category::withTrashed()->withoutGlobalScopes()->pluck('code')
            ->map(fn ($code) => strtoupper((string) $code))->flip();

        $words = $this->letterWords($name) ?: ['XX'];
        $first = $words[0][0];
        $candidates = [count($words) >= 2 ? $words[0][0].$words[1][0] : $this->pad(substr($words[0], 0, 2), 2)];
        foreach ([...range(1, 9), ...range('A', 'Z')] as $suffix) {
            $candidates[] = $first.$suffix;
        }
        foreach (range('A', 'Z') as $a) {
            foreach ([...range('A', 'Z'), ...range(0, 9)] as $b) {
                $candidates[] = $a.$b;
            }
        }

        foreach ($candidates as $code) {
            if (! $taken->has($code)) {
                return $code;
            }
        }

        throw new \RuntimeException("Tidak ada kode kategori yang bebas untuk '{$name}'.");
    }

    private function formatPrefix(string $outletCode, string $categoryCode, string $productSegment): string
    {
        $prefix = strtoupper($outletCode).'-'.strtoupper($categoryCode).'-'.strtoupper($productSegment);

        // 079 — kode outlet 3–5 karakter, kategori 2, segmen 3: prefiks 10–12 karakter (kolom `code_prefix` CHAR(12)).
        $valid = strlen($outletCode) >= OutletCodeRule::MIN && strlen($outletCode) <= OutletCodeRule::MAX
            && strlen($categoryCode) === 2 && strlen($productSegment) === 3;
        if (! $valid) {
            throw new \InvalidArgumentException('Kombinasi kode outlet (3–5), kategori (2), dan segmen produk (3) tidak valid (format OUTLET-CA-SEG).');
        }

        return $prefix;
    }

    // 003-seed-demo-live — withoutGlobalScope: code_prefix UNIQUE lintas
    // SELURUH tabel (bukan per data_mode), jadi pengecekan tabrakan di
    // sini juga harus lintas mode, sama seperti alasan di
    // OrderService::generateOrderNumber().
    private function prefixTaken(string $prefix): bool
    {
        return Product::withTrashed()->withoutGlobalScopes()->where('code_prefix', $prefix)->exists();
    }

    /** @return list<string> deret huruf dalam nama, huruf besar */
    private function letterWords(string $name): array
    {
        preg_match_all('/[A-Za-z]+/', $name, $matches);

        return array_map('strtoupper', $matches[0]);
    }

    private function pad(string $value, int $length): string
    {
        return str_pad(substr($value, 0, $length), $length, 'X');
    }

    /**
     * SKU varian berikutnya untuk satu produk (format "ART-CA-SEG-001",
     * urutan 3 digit). Urutan dihitung dari jumlah varian (termasuk yang
     * sudah dihapus/soft-deleted) agar nomor urut tidak pernah dipakai
     * ulang meski ada varian yang dihapus.
     */
    public function nextVariantSku(Product $product): string
    {
        $existingCount = ProductVariant::withTrashed()
            ->where('product_id', $product->id)
            ->count();

        $sequence = $existingCount + 1;

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $candidate = $product->code_prefix.'-'.str_pad((string) ($sequence + $attempt), 3, '0', STR_PAD_LEFT);

            if (! ProductVariant::withTrashed()->where('sku', $candidate)->exists()) {
                return $candidate;
            }
        }

        // Praktis tidak akan pernah tercapai (butuh >20 tabrakan berurutan),
        // tapi dilempar eksplisit alih-alih diam-diam mengembalikan SKU
        // yang salah.
        throw new \RuntimeException('Gagal menghasilkan SKU unik setelah 20 percobaan untuk produk ini.');
    }
}
