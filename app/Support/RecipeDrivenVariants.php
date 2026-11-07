<?php

namespace App\Support;

use App\Models\Recipe;
use Illuminate\Support\Facades\DB;

/**
 * 092 (research D6) — himpunan id varian yang punya resep AKTIF, dimuat dua kueri SEKALI per permintaan (scoped).
 * `ProductVariant::tracksOwnStock()` dipanggil per varian di banyak jalur (daftar menu, tiap baris pesanan, laporan);
 * tanpa memo ini setiap panggilan menjadi satu kueri `exists` dan daftar menu menjadi N+1 (Konstitusi V).
 *
 * Memo dikunci hari bisnis DAN mode data (DEMO/LIVE punya resep sendiri) karena "aktif" bergantung `effective_from` ≤ hari ini, dan dibuang oleh semua penulis
 * resep (`RecipeService`, serta event model `Recipe`). Penulis yang tidak memicu event model (hapus massal, sinkron
 * tautan varian) memanggil `forget()` secara eksplisit.
 */
class RecipeDrivenVariants
{
    /** @var array<int, true>|null */
    private ?array $ids = null;

    /** Kunci memo: hari bisnis + mode data (Konstitusi V: dikunci semua masukan yang mengubah isinya). */
    private ?string $key = null;

    /** @return array<int, true> id varian => true (set; cek dengan isset) */
    public function ids(): array
    {
        $key = BusinessDay::today().'|'.ModeGate::current();
        if ($this->ids !== null && $this->key === $key) {
            return $this->ids;
        }

        $recipeIds = Recipe::query()->active()->pluck('id');
        $this->ids = $recipeIds->isEmpty()
            ? []
            : array_fill_keys(DB::table('recipe_variants')->whereIn('recipe_id', $recipeIds)->pluck('variant_id')->map(fn ($id) => (int) $id)->all(), true);
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
