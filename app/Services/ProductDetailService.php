<?php

namespace App\Services;

use App\Models\MenuRelease;
use App\Models\Outlet;
use App\Models\OutletItemSetting;
use App\Models\VariantOutletPrice;
use App\Models\Product;
use App\Models\User;
use App\Support\MenuSettings;
use App\Support\OutletContext;
use App\Support\PermissionCatalog;

/**
 * 043-products-page-redesign — bagian tambahan GET /products/{id} untuk halaman item (fakta, stasiun,
 * tautan modifier dengan aturan EFEKTIF, ketersediaan, rilis, dan hak aksi pemanggil). Sumber aturan modifier
 * tetap ModifierResolver (tautan menimpa grup; kategori sebagai cadangan) — di sini hanya disajikan.
 */
class ProductDetailService
{
    /** @return array<string,mixed> */
    public function extras(Product $product, User $user): array
    {
        $product->loadMissing([
            'category.station', 'station',
            'modifierGroups.options', 'category.modifierGroups.options', 'excludedModifierGroups',
            'availabilityMarks' => fn ($m) => $m->active()->with(['reason', 'marker:id,name']),
        ]);

        $station = $product->station ?? $product->category?->station;
        return [
            'short_name' => $product->short_name,
            'tax_category' => $product->tax_category,
            'prep_minutes' => $product->prep_minutes,
            'is_retail' => (bool) $product->is_retail,
            'status' => $product->status,
            'published_at' => $product->published_at?->toIso8601String(),
            'discontinued_at' => $product->discontinued_at?->toIso8601String(),
            'station' => $station ? ['id' => $station->id, 'name' => $station->name, 'source' => $product->station ? 'product' : 'category'] : null,
            'release' => MenuSettings::releaseEnabled()
                ? ['version' => (int) (MenuRelease::query()->where('status', MenuRelease::PUBLISHED)->max('version') ?? 0), 'outlets_active' => 1]
                : null,
            'modifier_links' => $this->links($product),
            'modifier_excluded' => $this->excludedLinks($product),
            'availability' => $this->availabilityRows($product),
            'outlet_prices' => $this->outletPrices($product),
            'recipe' => $this->recipeSummary($product, $user),
            'can' => [
                'edit' => $user->can('update', $product),
                'mark_86' => $user->hasPermission(PermissionCatalog::ITEM_86),
                'discontinue' => $user->hasPermission(PermissionCatalog::ITEM_MANAGE),
                'bulk_price' => $user->hasPermission(PermissionCatalog::PRICE_BULK),
                'edit_recipe' => $user->hasPermission(PermissionCatalog::RECIPE_EDIT),
            ],
        ];
    }


    /**
     * Ketersediaan per outlet yang terlihat (045 Stage 3): satu baris per outlet — `not_carried`, `sold_out`
     * (tanda 86 outlet itu ATAU global) atau `available`. Satu outlet terlihat → bentuk lama (satu baris tanpa
     * outlet), supaya instalasi satu outlet tidak berubah.
     *
     * @return list<array<string,mixed>>
     */
    private function availabilityRows(Product $product): array
    {
        $ids = app(OutletContext::class)->effectiveIds();
        $outlets = count($ids) > 1 ? Outlet::query()->whereIn('id', $ids)->orderBy('id')->get(['id', 'code', 'name']) : collect();

        $row = fn ($mark, ?Outlet $o, string $state) => [
            'outlet_id' => $o?->id,
            'outlet_code' => $o?->code,
            'outlet_name' => $o?->name,
            'state' => $state,
            'reason' => $state === 'sold_out' ? $mark?->reason?->label() : null,
            'note' => $state === 'sold_out' ? $mark?->note : null,
            'marked_by' => $state === 'sold_out' ? $mark?->marker?->name : null,
            'marked_at' => $state === 'sold_out' ? $mark?->marked_at?->toIso8601String() : null,
            'expires_at' => $state === 'sold_out' ? $mark?->expires_at?->toIso8601String() : null,
            'daily_quota' => null,
            'channels' => null,
        ];

        if ($outlets->isEmpty()) {
            $mark = $product->availabilityMarks->first();

            return [$row($mark, null, $mark ? 'sold_out' : 'available')];
        }

        $notCarried = OutletItemSetting::query()->where('product_id', $product->id)->where('is_carried', false)
            ->pluck('outlet_id')->map(fn ($id) => (int) $id)->all();

        return $outlets->map(function (Outlet $o) use ($product, $notCarried, $row) {
            if (in_array($o->id, $notCarried, true)) {
                return $row(null, $o, 'not_carried');
            }
            $mark = $product->availabilityMarks->first(fn ($m) => $m->outlet_id === null || (int) $m->outlet_id === $o->id);

            return $row($mark, $o, $mark ? 'sold_out' : 'available');
        })->values()->all();
    }

    /**
     * Harga per outlet untuk tabel varian (045 Stage 3). Hanya bila lebih dari satu outlet terlihat; null = bentuk
     * lama. Hanya harga KHUSUS yang dikirim — varian/outlet tanpa baris mengikuti harga dasar.
     *
     * @return array{outlets: list<array<string,mixed>>, by_variant: array<int, array<int,string>>}|null
     */
    private function outletPrices(Product $product): ?array
    {
        $ids = app(OutletContext::class)->effectiveIds();
        if (count($ids) < 2) {
            return null;
        }

        $variantIds = $product->variants->pluck('id')->all();
        $byVariant = [];
        foreach (VariantOutletPrice::query()->whereIn('variant_id', $variantIds)->whereIn('outlet_id', $ids)->get() as $row) {
            $byVariant[(int) $row->variant_id][(int) $row->outlet_id] = number_format((float) $row->price, 2, '.', '');
        }

        return [
            'outlets' => Outlet::query()->whereIn('id', $ids)->orderBy('id')->get(['id', 'code', 'name'])
                ->map(fn (Outlet $o) => ['id' => $o->id, 'code' => $o->code, 'name' => $o->name])->values()->all(),
            'by_variant' => $byVariant,
        ];
    }

    /**
     * Ringkasan resep AKTIF varian pertama (null bila tidak ada). Bidang biaya hanya untuk pemegang izin biaya.
     * Daftar produk tidak memanggil ini (N+1); angka food cost di daftar berasal dari `cost_price` yang sudah
     * disinkronkan RecipeService dari rumus RecipeCost yang sama.
     */
    private function recipeSummary(Product $product, User $user): ?array
    {
        $recipes = app(RecipeService::class);
        $head = $product->variants->sortBy('id')->first();
        $recipe = $head ? $recipes->activeFor($head) : null;
        if ($recipe === null) {
            return null;
        }

        $full = $recipes->present($recipe, $recipes->mayCost($user));
        unset($full['lines']);

        return $full + ['line_count' => $recipe->lines()->count()];
    }

    /** Tautan (produk, lalu kategori) dengan aturan efektif: nilai tautan bila ada, selain itu nilai grup. */
    private function links(Product $product): array
    {
        $rows = [];
        foreach ($product->modifierGroups as $g) {
            $rows[$g->id] = $this->row($g, 'product', $g->pivot);
        }
        // 086 — grup warisan datang dari SATU pembaca (ModifierResolver::inheritedGroups): yang dilepas item ini tidak ikut.
        foreach (app(ModifierResolver::class)->inheritedGroups($product) as $g) {
            $rows[$g->id] ??= $this->row($g, 'category', null);
        }

        return collect($rows)->sortBy([['display_order', 'asc'], ['group_id', 'asc']])->values()->all();
    }

    /**
     * 086 — grup yang MASIH diwarisi kategori tetapi dilepas item ini, supaya UI bisa memulihkannya. Pengecualian yang
     * grupnya sudah tidak ada di kategori tidak ditampilkan (tetap tersimpan, tanpa galat).
     *
     * Bentuknya SAMA dengan baris `modifier_links` (aturan grup ikut), supaya UI bisa menampilkannya lagi sebagai warisan
     * begitu dipulihkan tanpa meminta ulang ke server.
     *
     * @return list<array<string, mixed>>
     */
    private function excludedLinks(Product $product): array
    {
        $excluded = $product->excludedModifierGroups->pluck('id');

        return ($product->category?->modifierGroups ?? collect())
            ->filter(fn ($g) => $excluded->contains($g->id))
            ->map(fn ($g) => $this->row($g, 'category', null))
            ->sortBy([['display_order', 'asc'], ['group_id', 'asc']])
            ->values()->all();
    }

    private function row($g, string $source, $pivot): array
    {
        $rules = $pivot !== null && (bool) ($pivot->has_rules ?? false);

        return [
            'group_id' => $g->id,
            'name' => $g->name,
            'source' => $source,
            'has_rules' => $rules,
            // Aturan tautan (has_rules) menimpa grup; `max_select` NULL pada tautan = tanpa batas atas.
            'is_required' => (bool) ($rules ? $pivot->is_required : $g->is_required),
            'min_select' => (int) ($rules ? $pivot->min_select : $g->min_select),
            'max_select' => $rules ? $pivot->max_select : $g->max_select,
            'display_order' => (int) ($rules ? ($pivot->display_order ?? $g->display_order) : $g->display_order),
            'options' => $g->options->where('is_active', true)->pluck('name')->values()->all(),
        ];
    }
}
