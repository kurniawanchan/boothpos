<?php

namespace App\Services;

use App\Models\Concerns\DataModeScope;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\ProductSegment;
use Illuminate\Validation\ValidationException;

/**
 * Menghasilkan code_prefix (paling panjang 13 karakter) dan SKU varian (paling panjang 20),
 * batasnya di ProductSegment::MAX_* (spec 046),
 * format dipisah tanda hubung atas permintaan pengguna (sebelumnya
 * digabung tanpa pemisah — lihat migration
 * 2026_10_25_000001_widen_product_code_and_sku_columns_for_dashed_format
 * untuk riwayat perubahan lebar kolom):
 *   code_prefix = artist.code(3) + "-" + category.code(2) + "-" + product_segment(1–3)
 *   sku         = code_prefix(8–10) + "-" + urutan 3 digit
 *
 * Kode bersifat PERMANEN setelah dibuat (F19.4) — service ini hanya
 * dipanggil sekali saat entitas dibuat, tidak pernah untuk regenerasi.
 * Kode/SKU yang sudah ada dari SEBELUM perubahan format ini tetap dalam
 * bentuk lama (tanpa tanda hubung) — tidak pernah diregenerasi ulang.
 */
class ProductCodeGenerator
{
    /**
     * Menurunkan segmen 3 huruf dari nama produk bila tidak diberikan
     * (segmen yang DIKETIK kini sampai 6 karakter, lihat ProductSegment — spec 046)
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
    public function buildCodePrefix(string $artistCode, string $categoryCode, string $productSegment): string
    {
        $prefix = strtoupper($artistCode).'-'.strtoupper($categoryCode).'-'.strtoupper($productSegment);

        // Bentuk diperiksa lewat ProductSegment::CODE_PATTERN (segmen 1–6 karakter,
        // boleh satu tanda hubung, spec 046) — bukan panjang tetap, karena segmen bervariasi.
        if (! preg_match(ProductSegment::CODE_PATTERN, $prefix)) {
            throw new \InvalidArgumentException('Kombinasi kode artist, kategori, dan segmen produk harus berbentuk ART-CA-SEG (segmen 1–6 karakter).');
        }

        // 003-seed-demo-live — withoutGlobalScope: code_prefix UNIQUE lintas
        // SELURUH tabel (bukan per data_mode), jadi pengecekan tabrakan di
        // sini juga harus lintas mode, sama seperti alasan di
        // OrderService::generateOrderNumber().
        if (Product::withTrashed()->withoutGlobalScope(DataModeScope::class)->where('code_prefix', $prefix)->exists()) {
            throw ValidationException::withMessages([
                'product_segment' => "Kombinasi kode ini ({$prefix}) sudah dipakai produk lain. Ubah segmen nama produk secara manual.",
            ]);
        }

        return $prefix;
    }

    /**
     * Nomor urut yang diketik pemilik (spec 045): hanya digit, minimal 1. Nol di
     * depan hilang karena hasilnya int (`007` → 7). Null bila tidak valid.
     */
    public function normaliseSequence(string $raw): ?int
    {
        $trimmed = trim($raw);
        if ($trimmed === '' || ! ctype_digit($trimmed)) {
            return null;
        }

        $number = (int) $trimmed;

        return $number >= 1 ? $number : null;
    }

    /**
     * Nomor di ujung SKU setelah tanda hubung terakhir, apa pun kode awalnya:
     * `SPF-PI-S-7` → 7. Dipakai untuk SKU yang tetap memakai kode lama setelah
     * ganti segmen (spec 045). Null bila tidak ada tanda hubung atau tidak ada digit.
     */
    public function trailingNumber(string $sku): ?int
    {
        if (! str_contains($sku, '-')) {
            return null;
        }

        $tail = substr($sku, strrpos($sku, '-') + 1);

        return ctype_digit($tail) && $tail !== '' ? (int) $tail : null;
    }

    /**
     * Susunan SKU varian: kode produk, tanda hubung, lalu nomor TANPA nol di depan
     * (VLC-SK-S-1, VLC-SK-S-11). Satu-satunya tempat format ini didefinisikan:
     * pembuatan varian, impor, dan ganti segmen memanggil method ini (spec 044).
     */
    public function variantSku(string $prefix, int $number): string
    {
        return $prefix.'-'.$number;
    }

    /**
     * Nomor urut sebuah SKU MILIK kode produk $prefix: `SPF-SH-S-001` → 1,
     * `SPF-SH-S-11` → 11, `RYUKYSAK0001` → 1 (SKU lama tanpa tanda hubung, prefix
     * `RYUKYSAK`). Null bila SKU tidak berawalan kode itu — SKU yang bukan milik
     * kode ini tidak boleh ikut menentukan nomor berikutnya.
     */
    public function sequenceOf(string $prefix, string $sku): ?int
    {
        return preg_match('/^'.preg_quote($prefix, '/').'-?(\d+)$/', $sku, $m) ? (int) $m[1] : null;
    }

    /**
     * SKU varian berikutnya untuk satu produk (format "ART-CA-SEG-1",
     * urutan 3 digit). Urutan dihitung dari jumlah varian (termasuk yang
     * sudah dihapus/soft-deleted) agar nomor urut tidak pernah dipakai
     * ulang meski ada varian yang dihapus.
     */
    public function nextVariantSku(Product $product): string
    {
        // Nomor berikutnya = nomor tertinggi yang pernah dipakai + 1, dihitung dari
        // SEMUA varian (termasuk yang dihapus). Hitung baris saja tidak cukup:
        // SKU lama (mis. RYUKYSAK0001) tidak selalu sesuai jumlah baris (spec 044).
        // Varian yang sudah dihapus tidak ikut: nomornya bebas dipakai lagi (spec 045, FR-016).
        // Varian nonaktif (dibatasi riwayat) tidak ikut: nomornya bebas dipakai (spec 045).
        $highest = ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->pluck('sku')
            // Nomor dari SKU yang berawalan kode sekarang, atau dari ujung SKU untuk
            // SKU yang diketik dan tetap memakai kode lama (spec 045, research D5).
            ->map(fn (string $sku) => $this->sequenceOf($product->code_prefix, $sku) ?? $this->trailingNumber($sku) ?? 0)
            ->max() ?? 0;

        $sequence = $highest + 1;

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $candidate = $this->variantSku($product->code_prefix, $sequence + $attempt);

            // Kolom sku CHAR(20) (spec 046): SKU yang lebih panjang ditolak di sini dengan
            // pesan jelas, bukan galat basis data (500) di tengah transaksi.
            if (strlen($candidate) > ProductSegment::MAX_SKU_LENGTH) {
                throw new \RuntimeException('Nomor SKU sudah melebihi lebar kolom sku ('.ProductSegment::MAX_SKU_LENGTH.' karakter) untuk kode produk ini.');
            }

            if (! ProductVariant::query()->where('sku', $candidate)->where('is_active', true)->exists()) {
                return $candidate;
            }
        }

        // Praktis tidak akan pernah tercapai (butuh >20 tabrakan berurutan),
        // tapi dilempar eksplisit alih-alih diam-diam mengembalikan SKU
        // yang salah.
        throw new \RuntimeException('Gagal menghasilkan SKU unik setelah 20 percobaan untuk produk ini.');
    }
}
