<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Customer commerce — SATU definisi aturan slug produk, dipakai oleh hook
 * `creating` di App\Models\Product dan oleh backfill migrasi
 * `2026_11_27_000001_add_slug_and_published_at_to_products_table`.
 *
 * Slug adalah pengenal publik produk di etalase (`/storefront/products/{slug}`).
 * `code_prefix` dan SKU tidak bisa dipakai untuk itu: keduanya ditulis ulang
 * saat segmen produk diubah (ProductSegmentChangeService), sedangkan tautan
 * dan cache aplikasi Android butuh pengenal yang tidak pernah berubah.
 *
 * Aturan: Str::slug(nama) → dipotong 140 karakter → "produk" bila kosong
 * (nama tanpa huruf/angka Latin) → akhiran -2, -3, … sampai bebas di dalam
 * mode data baris itu. Kelas ini murni (tanpa query); pemanggil yang
 * menyediakan daftar slug terpakai.
 */
final class ProductSlug
{
    /** Panjang maksimum bagian dasar; sisa lebar kolom untuk akhiran pembeda. */
    public const MAX_BASE = 140;

    /** Lebar kolom products.slug. */
    public const COLUMN_LENGTH = 160;

    public const FALLBACK = 'produk';

    public static function base(?string $name): string
    {
        // Str::slug hanya menghasilkan [a-z0-9-], jadi substr aman (tanpa multibyte).
        $slug = rtrim(substr(Str::slug((string) $name), 0, self::MAX_BASE), '-');

        return $slug === '' ? self::FALLBACK : $slug;
    }

    /**
     * @param  array<string,mixed>  $taken  slug yang sudah terpakai sebagai KUNCI array
     */
    public static function dedupe(string $base, array $taken): string
    {
        if (! isset($taken[$base])) {
            return $base;
        }

        for ($n = 2; ; $n++) {
            if (! isset($taken["{$base}-{$n}"])) {
                return "{$base}-{$n}";
            }
        }
    }
}
