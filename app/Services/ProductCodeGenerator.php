<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

/**
 * Menghasilkan code_prefix (8 karakter) dan SKU varian (12 karakter)
 * sesuai keputusan desain di PRD 7.19 dan schema-pos-mvp.sql:
 *   code_prefix = artist.code(3) + category.code(2) + product_segment(3)
 *   sku         = code_prefix(8) + urutan 4 digit
 *
 * Kode bersifat PERMANEN setelah dibuat (F19.4) — service ini hanya
 * dipanggil sekali saat entitas dibuat, tidak pernah untuk regenerasi.
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
     * Menyusun code_prefix. TIDAK mencoba "pintar" menghindari tabrakan
     * dengan mengubah-ubah segmen secara otomatis — bila tabrakan terjadi,
     * lempar exception agar admin diminta menyunting product_segment
     * secara manual (F19.5), sesuai KISS: jangan menebak niat pengguna.
     */
    public function buildCodePrefix(string $artistCode, string $categoryCode, string $productSegment): string
    {
        $prefix = strtoupper($artistCode).strtoupper($categoryCode).strtoupper($productSegment);

        if (strlen($prefix) !== 8) {
            throw new \InvalidArgumentException('Kombinasi kode artist, kategori, dan segmen produk harus menghasilkan 8 karakter.');
        }

        if (Product::withTrashed()->where('code_prefix', $prefix)->exists()) {
            throw ValidationException::withMessages([
                'product_segment' => "Kombinasi kode ini ({$prefix}) sudah dipakai produk lain. Ubah segmen nama produk secara manual.",
            ]);
        }

        return $prefix;
    }

    /**
     * SKU varian berikutnya untuk satu produk. Urutan dihitung dari jumlah
     * varian (termasuk yang sudah dihapus/soft-deleted) agar nomor urut
     * tidak pernah dipakai ulang meski ada varian yang dihapus.
     */
    public function nextVariantSku(Product $product): string
    {
        $existingCount = ProductVariant::withTrashed()
            ->where('product_id', $product->id)
            ->count();

        $sequence = $existingCount + 1;

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $candidate = $product->code_prefix.str_pad((string) ($sequence + $attempt), 4, '0', STR_PAD_LEFT);

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
