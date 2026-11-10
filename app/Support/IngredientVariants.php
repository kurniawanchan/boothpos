<?php

namespace App\Support;

use App\Models\Recipe;
use App\Models\RecipeLine;

/**
 * 093 (research D5, D8) — himpunan id varian yang dirujuk baris resep TIDAK-arsip: "varian ini dipakai sebagai bahan".
 * Meniru RecipeDrivenVariants: dua kueri SEKALI per permintaan (scoped), karena penjagaan tahanan dan validasi
 * memeriksanya per varian di banyak jalur dan tanpa memo setiap pemeriksaan menjadi satu kueri (N+1, Konstitusi V).
 *
 * Mencakup resep berlaku-masa-depan, berbeda dari RecipeDrivenVariants (yang hanya resep AKTIF): himpunan ini dipakai
 * MENJAGA (menolak ubah/hapus, menahan penjualan langsung), bukan memotong stok, dan menjaga lebih awal lebih aman.
 * Tahanan sendiri tetap dihitung hanya dari kebutuhan resep aktif, jadi kelebihan anggota di sini tidak mengubah angka.
 *
 * Memo dikunci hari bisnis + mode data (sama dengan RecipeDrivenVariants) dan dibuang oleh setiap penulis resep dan
 * baris resep (event model + RecipeService).
 */
class IngredientVariants
{
    /** @var array<int, true>|null */
    private ?array $ids = null;

    private ?string $key = null;

    /** @return array<int, true> id varian => true (set; cek dengan isset) */
    public function ids(): array
    {
        $key = BusinessDay::today().'|'.ModeGate::current();
        if ($this->ids !== null && $this->key === $key) {
            return $this->ids;
        }

        $recipeIds = Recipe::query()->whereNull('archived_at')->pluck('id');
        $this->ids = $recipeIds->isEmpty()
            ? []
            : array_fill_keys(
                RecipeLine::query()->whereIn('recipe_id', $recipeIds)->whereNotNull('variant_id')->distinct()->pluck('variant_id')->map(fn ($id) => (int) $id)->all(),
                true,
            );
        $this->key = $key;

        return $this->ids;
    }

    public function has(int $variantId): bool
    {
        return isset($this->ids()[$variantId]);
    }

    public function forget(): void
    {
        $this->ids = null;
        $this->key = null;
    }
}
