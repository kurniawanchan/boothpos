<?php

namespace App\Services;

use App\Models\MenuRelease;
use App\Models\Product;
use App\Models\User;
use App\Support\MenuSettings;
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
            'modifierGroups.options', 'category.modifierGroups.options',
            'availabilityMarks' => fn ($m) => $m->active()->with(['reason', 'marker:id,name']),
        ]);

        $station = $product->station ?? $product->category?->station;
        $mark = $product->availabilityMarks->first();

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
            'availability' => [[
                'outlet_id' => null,
                'state' => $mark ? 'sold_out' : 'available',
                'reason' => $mark?->reason?->label(),
                'note' => $mark?->note,
                'marked_by' => $mark?->marker?->name,
                'marked_at' => $mark?->marked_at?->toIso8601String(),
                'expires_at' => $mark?->expires_at?->toIso8601String(),
                'daily_quota' => null,
                'channels' => null,
            ]],
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
        foreach ($product->category?->modifierGroups ?? [] as $g) {
            $rows[$g->id] ??= $this->row($g, 'category', null);
        }

        return collect($rows)->sortBy([['display_order', 'asc'], ['group_id', 'asc']])->values()->all();
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
