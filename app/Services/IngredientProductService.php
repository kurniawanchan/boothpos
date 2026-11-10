<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 093 — aturan SATU-SATUNYA untuk "produk ini boleh/tidak boleh menjadi bahan resep" di sisi PRODUK (sisi baris resep
 * ada di RecipeService::validate, dan keduanya saling melengkapi supaya rekursi tertutup dari dua arah — research D8).
 *
 * Alasan aturannya: produk bahan memakai SATU saldo varian bersama penjualan langsung. Itu hanya bermakna bila
 *  - setiap varian melacak stok dan stoknya bersumber dari varian (bukan `modifier`), dan
 *  - tidak ada varian yang stoknya DITURUNKAN dari resep (menu beresep tidak punya saldo sendiri — 092).
 * Resep arsip tidak dihitung (tidak memotong, tidak menahan); resep berlaku-masa-depan dihitung supaya penanda tidak
 * bisa dinyalakan lalu stoknya terputus pada hari resep itu berlaku.
 */
class IngredientProductService
{
    /**
     * @param  string|null  $stockSource  nilai yang AKAN berlaku bila permintaan yang sama juga mengubah sumber stok
     *
     * @throws ValidationException (422) pada `use_as_ingredient`
     */
    public function assertCanFlag(Product $product, ?string $stockSource = null): void
    {
        if (($stockSource ?? $product->stock_source ?? Product::STOCK_VARIANT) !== Product::STOCK_VARIANT) {
            throw ValidationException::withMessages(['use_as_ingredient' => __('products.ingredient_flag_untracked')]);
        }

        if ($product->variants()->where('track_stock', false)->exists()) {
            throw ValidationException::withMessages(['use_as_ingredient' => __('products.ingredient_flag_untracked')]);
        }

        if ($this->variantsWithRecipe($product)) {
            throw ValidationException::withMessages(['use_as_ingredient' => __('products.ingredient_flag_has_recipe')]);
        }
    }

    /**
     * Varian yang boleh dipilih di resep BARU: produk bertanda bahan + aktif + stok bersumber varian, varian aktif +
     * melacak stok + tidak beresep (rekursi), minus produk resep yang sedang disunting. Stok = saldo outlet yang berlaku
     * dikurangi tahanan; tanpa SATU outlet yang berlaku angkanya null (tidak dijumlahkan lintas outlet, supaya tidak
     * menyiratkan stok yang tak bisa dipakai di outlet pemesanan).
     *
     * Produk disaring lewat subkueri (bukan join) supaya scope DEMO/LIVE tiap model tetap berlaku tanpa kolom ambigu.
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, array<string, mixed>>
     */
    public function pickable(?int $outletId, ?int $excludeProductId, ?string $search, int $perPage): \Illuminate\Pagination\LengthAwarePaginator
    {
        $products = Product::query()->select('id')
            ->where('use_as_ingredient', true)->where('is_active', true)->where('stock_source', Product::STOCK_VARIANT)
            ->when($excludeProductId, fn ($q) => $q->where('id', '!=', $excludeProductId));

        $search = trim((string) $search);
        $page = \App\Models\ProductVariant::query()
            ->with('product:id,name,ingredient_unit')
            ->whereIn('product_id', $products)
            ->where('is_active', true)->where('track_stock', true)
            ->whereNotIn('id', DB::table('recipe_variants')->join('recipes', 'recipes.id', '=', 'recipe_variants.recipe_id')->whereNull('recipes.archived_at')->select('recipe_variants.variant_id'))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('sku', 'like', "%{$search}%")->orWhere('variant_name', 'like', "%{$search}%")
                ->orWhereIn('product_id', Product::query()->select('id')->where('name', 'like', "%{$search}%"))))
            ->orderBy(Product::query()->select('name')->whereColumn('products.id', 'product_variants.product_id'))
            ->orderBy('variant_name')->orderBy('id')
            ->paginate($perPage);

        $ids = $page->getCollection()->pluck('id')->all();
        $onHand = $outletId === null || $ids === [] ? [] : \App\Models\VariantOutletStock::query()
            ->where('outlet_id', $outletId)->whereIn('variant_id', $ids)->pluck('current_stock', 'variant_id')->map(fn ($n) => (int) $n)->all();
        $held = $outletId === null || $ids === [] ? [] : app(MenuStockService::class)->heldVariantsBy($outletId, null);

        return $page->through(function (\App\Models\ProductVariant $v) use ($outletId, $onHand, $held) {
            $have = $outletId === null ? null : ($onHand[$v->id] ?? 0);
            $hold = $outletId === null ? null : (int) ($held[$v->id] ?? 0);

            return [
                'variant_id' => $v->id, 'product_id' => $v->product_id,
                'product_name' => $v->product?->name, 'variant_name' => $v->variant_name, 'sku' => $v->sku,
                'unit' => $v->product?->ingredient_unit ?: 'unit',
                'on_hand' => $have, 'held' => $hold, 'available' => $have === null ? null : $have - $hold,
                'is_active' => (bool) $v->is_active,
            ];
        });
    }

    /**
     * Resep TIDAK-arsip yang baris bahannya merujuk salah satu varian ini. Memo `IngredientVariants` menjawab "tidak ada"
     * tanpa kueri tambahan, jadi produk biasa tidak membayar apa pun.
     *
     * @param  list<int>|null  $variantIds  null = semua varian produk
     * @return list<array{id: int, name: string, product_name: string|null}>
     */
    public function recipesUsing(Product $product, ?array $variantIds = null): array
    {
        $ids = $variantIds ?? $product->variants()->withTrashed()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ids = array_values(array_filter($ids, fn (int $id) => app(\App\Support\IngredientVariants::class)->has($id)));
        if ($ids === []) {
            return [];
        }

        $recipeIds = \App\Models\RecipeLine::query()->whereIn('variant_id', $ids)->pluck('recipe_id')->unique();

        return \App\Models\Recipe::query()->whereNull('archived_at')->whereIn('id', $recipeIds)->with('product:id,name')->orderBy('name')->orderBy('id')->get()
            ->map(fn (\App\Models\Recipe $r) => ['id' => $r->id, 'name' => $r->name, 'product_name' => $r->product?->name])->values()->all();
    }

    /**
     * Tolak (409) perubahan yang akan menghentikan pemakaian resep. Satu aturan untuk semua jalur: ubah produk, ubah/batch
     * varian, diskontinu, hapus permanen.
     *
     * @param  list<int>|null  $variantIds  null = semua varian produk
     *
     * @throws \App\Exceptions\IngredientInUseException
     */
    public function assertNotInUse(Product $product, ?array $variantIds = null): void
    {
        $recipes = $this->recipesUsing($product, $variantIds);
        if ($recipes === []) {
            return;
        }

        throw new \App\Exceptions\IngredientInUseException(
            __('products.ingredient_in_use', ['recipes' => implode(', ', array_map(fn ($r) => $r['name'].' ('.($r['product_name'] ?? '—').')', $recipes))]),
            $recipes,
        );
    }

    /** Ada varian produk ini yang ditautkan ke resep TIDAK-arsip (aktif maupun berlaku-masa-depan). */
    public function variantsWithRecipe(Product $product): bool
    {
        return DB::table('recipe_variants')
            ->join('recipes', 'recipes.id', '=', 'recipe_variants.recipe_id')
            ->whereNull('recipes.archived_at')
            ->whereIn('recipe_variants.variant_id', $product->variants()->select('product_variants.id'))
            ->exists();
    }
}
