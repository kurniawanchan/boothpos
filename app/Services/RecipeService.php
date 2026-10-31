<?php

namespace App\Services;

use App\Exceptions\BomRuleException;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\User;
use App\Support\ModeGate;
use App\Support\PermissionCatalog;
use App\Support\RecipeCost;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 043-products-page-redesign (Q3 = B, research D12) — resep berversi di samping BOM berbasis PO.
 *
 * - Sebuah versi TIDAK PERNAH diubah setelah dibuat; simpan = versi berikutnya. Versi aktif sebuah varian =
 *   versi terbaru yang memuat varian itu dengan `effective_from` ≤ hari ini.
 * - Satu varian punya SATU sumber biaya: resep ATAU BOM-selesai. Memilih varian BOM-selesai untuk resep
 *   ditolak 409 `cost_source_conflict`, dan sebaliknya (VariantBomService::complete memanggil `isRecipeDriven`).
 * - Service ini penulis SATU-SATUNYA `cost_price` varian yang digerakkan resep (seperti VariantBomService untuk
 *   BOM selesai): saat versi mulai berlaku (malas, lewat `applyDueCached`) dan saat "Hitung ulang biaya".
 *   `order_items.cost_price` yang sudah tercatat tidak pernah berubah.
 * - TIDAK ADA pemotongan stok bahan dan tak ada konversi satuan (satuan baris = satuan bahan).
 */
class RecipeService
{
    /** Tanggal berlaku paling jauh sekian hari ke belakang (mencegah resep "diam-diam" berlaku surut). */
    public const MAX_BACKDATE_DAYS = 30;

    public function __construct(private ActivityLogger $log) {}

    // --- tulis -------------------------------------------------------------------------------------

    /**
     * @param  array{yield: float|int|string, effective_from: string, variant_ids: list<int>, lines: list<array<string, mixed>>, note?: string|null}  $data
     *
     * @throws ValidationException|BomRuleException
     */
    public function create(Product $product, array $data, User $by): Recipe
    {
        $this->validate($product, $data);

        return DB::transaction(function () use ($product, $data, $by) {
            Product::query()->whereKey($product->id)->lockForUpdate()->first();   // serialisasi nomor versi

            $variants = ProductVariant::query()->whereIn('id', $data['variant_ids'])->where('product_id', $product->id)->lockForUpdate()->get();
            $conflict = $variants->where('bom_complete', true);
            if ($conflict->isNotEmpty()) {
                throw new BomRuleException(
                    __('products.recipe_conflict_bom', ['skus' => $conflict->pluck('sku')->implode(', ')]),
                    'cost_source_conflict',
                    ['variants' => $conflict->map(fn ($v) => ['id' => $v->id, 'sku' => $v->sku])->values()->all()],
                );
            }

            $recipe = Recipe::create([
                'product_id' => $product->id,
                'version' => (int) Recipe::query()->where('product_id', $product->id)->max('version') + 1,
                'yield' => $data['yield'],
                'effective_from' => $data['effective_from'],
                'note' => $data['note'] ?? null,
                'created_by' => $by->id,
            ]);
            $recipe->variants()->sync($variants->pluck('id')->all());
            foreach (array_values($data['lines']) as $i => $l) {
                $recipe->lines()->create([
                    'material_id' => (int) $l['material_id'], 'quantity' => $l['quantity'],
                    'shrinkage_pct' => $l['shrinkage_pct'] ?? 0, 'display_order' => $i + 1,
                ]);
            }

            $this->log->log(userId: $by->id, action: 'recipe_created', entityType: 'Product', entityId: $product->id,
                description: "Resep {$product->name} v{$recipe->version} dibuat.", newValues: ['recipe_id' => $recipe->id, 'version' => $recipe->version]);

            $this->applyDue($by->id);

            return $recipe->fresh(['lines.material', 'variants']);
        });
    }

    /**
     * 2026-10-31 (permintaan pemilik: "bisa hapus resep") — menghapus SEMUA versi resep sebuah item. Varian berhenti
     * digerakkan resep (harga modal boleh diubah tangan lagi); harga modal terakhir dibiarkan apa adanya, dan
     * `order_items.cost_price` yang sudah tercatat tidak pernah berubah. Isi tiap versi disimpan di log aktivitas
     * karena barisnya ikut terhapus (baris resep dan tautan varian CASCADE).
     *
     * @return int banyaknya versi yang dihapus (0 = item tidak punya resep)
     */
    public function remove(Product $product, User $by): int
    {
        return DB::transaction(function () use ($product, $by) {
            Product::query()->whereKey($product->id)->lockForUpdate()->first();
            $versions = Recipe::query()->where('product_id', $product->id)->with(['lines', 'variants:product_variants.id'])->orderBy('version')->get();
            if ($versions->isEmpty()) {
                return 0;
            }

            $this->log->log(
                userId: $by->id, action: 'recipe_removed', entityType: 'Product', entityId: $product->id,
                description: "Resep {$product->name} dihapus ({$versions->count()} versi).",
                oldValues: ['versions' => $versions->map(fn (Recipe $r) => [
                    'version' => $r->version, 'yield' => (float) $r->yield, 'effective_from' => $r->effective_from?->toDateString(),
                    'variant_ids' => $r->variants->pluck('id')->all(),
                    'lines' => $r->lines->map(fn ($l) => ['material_id' => $l->material_id, 'quantity' => (float) $l->quantity, 'shrinkage_pct' => (float) $l->shrinkage_pct])->all(),
                ])->all()],
            );
            Recipe::query()->whereKey($versions->pluck('id'))->delete();

            return $versions->count();
        });
    }

    /**
     * "Hitung ulang biaya": menulis ulang `cost_price` varian yang digerakkan resep dari biaya porsi SAAT INI
     * (harga vendor bisa berubah). Varian dengan biaya tak lengkap dilewati dan dilaporkan.
     *
     * @return array{updated: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     */
    public function recalculate(Product $product, User $by): array
    {
        return DB::transaction(function () use ($product, $by) {
            $updated = [];
            $skipped = [];

            foreach ($product->variants()->get() as $variant) {
                $recipe = $this->activeFor($variant);
                if ($recipe === null) {
                    continue;
                }
                $cost = $this->cost($recipe, (float) $variant->sell_price);
                if ($cost['incomplete']) {
                    $skipped[] = ['variant_id' => $variant->id, 'sku' => $variant->sku, 'reason' => 'incomplete'];

                    continue;
                }
                $this->writeCost($variant, $cost['portion_cost'], $by->id, $recipe);
                $updated[] = ['variant_id' => $variant->id, 'sku' => $variant->sku, 'cost_price' => number_format($cost['portion_cost'], 2, '.', '')];
            }

            Recipe::query()->where('product_id', $product->id)->whereDate('effective_from', '<=', Carbon::today())->whereNull('cost_applied_at')->update(['cost_applied_at' => now()]);

            return ['updated' => $updated, 'skipped' => $skipped];
        });
    }

    /**
     * Menerapkan biaya versi yang SUDAH berlaku tetapi belum diterapkan (versi dengan tanggal berlaku di hari
     * ini atau yang lalu, dan yang tanggalnya baru tiba). Tanpa penjadwal: dipanggil malas di titik masuk.
     */
    public function applyDue(?int $userId = null): void
    {
        $due = Recipe::query()->whereNull('cost_applied_at')->whereDate('effective_from', '<=', Carbon::today())->orderBy('effective_from')->orderBy('version')->get();

        foreach ($due as $recipe) {
            DB::transaction(function () use ($recipe, $userId) {
                foreach ($recipe->variants()->get() as $variant) {
                    // Hanya bila versi ini memang yang aktif untuk varian itu (versi lebih baru bisa sudah menggantikannya).
                    if ($this->activeFor($variant)?->id !== $recipe->id) {
                        continue;
                    }
                    $cost = $this->cost($recipe, (float) $variant->sell_price);
                    if (! $cost['incomplete']) {
                        $this->writeCost($variant, $cost['portion_cost'], $userId, $recipe);
                    }
                }
                $recipe->update(['cost_applied_at' => now()]);
            });
        }
    }

    /** `applyDue()` paling sering sekali per 10 detik per mode data. */
    public function applyDueCached(): void
    {
        if (Cache::add('recipe.apply_due:'.ModeGate::current(), 1, 10)) {
            $this->applyDue();
        }
    }

    // --- baca --------------------------------------------------------------------------------------

    /** Versi aktif sebuah varian: terbaru menurut `effective_from`, lalu nomor versi. */
    public function activeFor(ProductVariant $variant): ?Recipe
    {
        return Recipe::query()
            ->whereHas('variants', fn ($q) => $q->where('product_variants.id', $variant->id))
            ->whereDate('effective_from', '<=', Carbon::today())
            ->orderByDesc('effective_from')->orderByDesc('version')
            ->first();
    }

    public function isRecipeDriven(ProductVariant $variant): bool
    {
        return $this->activeFor($variant) !== null;
    }

    /** @return array{line_costs: list<float|null>, portion_cost: float, incomplete: bool, food_cost_pct: float|null, gross_margin: float|null, unit_costs: list<float|null>} */
    public function cost(Recipe $recipe, ?float $basePrice): array
    {
        $recipe->loadMissing('lines.material');
        $units = $recipe->lines->map(fn ($l) => $this->unitCost($l->material))->all();
        $inputs = $recipe->lines->values()->map(fn ($l, $i) => ['quantity' => $l->quantity, 'shrinkage_pct' => $l->shrinkage_pct, 'unit_cost' => $units[$i]])->all();

        return RecipeCost::compute($inputs, (float) $recipe->yield, $basePrice) + ['unit_costs' => array_values($units)];
    }

    /** Biaya satuan bahan: harga vendor acuan; bahan setengah jadi tanpa harga vendor memakai `standard_cost`. */
    public function unitCost(?Material $material): ?float
    {
        if ($material === null) {
            return null;
        }
        $price = $material->referencePrice()?->price;
        if ($price !== null) {
            return (float) $price;
        }

        return $material->kind === 'semi_finished' && $material->standard_cost !== null ? (float) $material->standard_cost : null;
    }

    /** @return list<array<string, mixed>> daftar versi (terbaru dulu) */
    public function versions(Product $product, bool $mayCost): array
    {
        $head = $product->variants()->orderBy('id')->first();
        $recipes = Recipe::query()->where('product_id', $product->id)->with(['variants', 'lines.material'])->orderByDesc('version')->get();
        $activeIds = $product->variants()->get()->map(fn ($v) => $this->activeFor($v)?->id)->filter()->unique()->all();

        return $recipes->map(function (Recipe $r) use ($mayCost, $head, $activeIds) {
            $row = [
                'id' => $r->id, 'version' => $r->version, 'yield' => $this->num($r->yield, 3),
                'effective_from' => $r->effective_from->toDateString(), 'note' => $r->note,
                'created_by' => $r->creator?->name, 'created_at' => $r->created_at?->toIso8601String(),
                'variants' => $r->variants->map(fn ($v) => ['id' => $v->id, 'sku' => $v->sku, 'name' => $v->variant_name])->values()->all(),
                'is_active_now' => in_array($r->id, $activeIds, true),
            ];
            if ($mayCost) {
                $c = $this->cost($r, $head ? (float) $head->sell_price : null);
                $row += ['portion_cost' => $this->num($c['portion_cost'], 2), 'incomplete' => $c['incomplete'], 'food_cost_pct' => $c['food_cost_pct']];
            }

            return $row;
        })->all();
    }

    /** @return array<string, mixed> satu versi lengkap dengan baris-barisnya */
    public function present(Recipe $recipe, bool $mayCost): array
    {
        $recipe->loadMissing(['lines.material', 'variants']);
        $head = $recipe->variants->sortBy('id')->first();
        $c = $mayCost ? $this->cost($recipe, $head ? (float) $head->sell_price : null) : null;

        $lines = $recipe->lines->values()->map(function ($l, $i) use ($c) {
            $row = [
                'material_id' => $l->material_id, 'name' => $l->material?->name, 'unit' => $l->material?->unit, 'kind' => $l->material?->kind,
                'quantity' => $this->num($l->quantity, 4), 'shrinkage_pct' => $this->num($l->shrinkage_pct, 2),
            ];
            if ($c) {
                $row += [
                    'unit_cost' => $c['unit_costs'][$i] === null ? null : $this->num($c['unit_costs'][$i], 4),
                    'line_cost' => $c['line_costs'][$i] === null ? null : $this->num($c['line_costs'][$i], 4),
                ];
            }

            return $row;
        })->all();

        return [
            'id' => $recipe->id, 'product_id' => $recipe->product_id, 'version' => $recipe->version,
            'yield' => $this->num($recipe->yield, 3), 'effective_from' => $recipe->effective_from->toDateString(), 'note' => $recipe->note,
            'variants' => $recipe->variants->map(fn ($v) => ['id' => $v->id, 'sku' => $v->sku, 'name' => $v->variant_name])->values()->all(),
            'lines' => $lines,
        ] + ($c ? [
            'portion_cost' => $this->num($c['portion_cost'], 2), 'incomplete' => $c['incomplete'],
            'food_cost_pct' => $c['food_cost_pct'], 'gross_margin' => $c['gross_margin'] === null ? null : $this->num($c['gross_margin'], 2),
        ] : []);
    }

    /** Boleh melihat biaya: menu produk/laporan DAN (izin ubah resep atau pemilik/admin lewat bawaan peran). */
    public function mayCost(User $user): bool
    {
        return ($user->canAccessMenu('products') || $user->canAccessMenu('reports')) && $user->hasPermission(PermissionCatalog::RECIPE_EDIT);
    }

    // --- internal ----------------------------------------------------------------------------------

    private function writeCost(ProductVariant $variant, float $portionCost, ?int $userId, Recipe $recipe): void
    {
        $new = round($portionCost, 2);
        if (abs((float) $variant->cost_price - $new) < 0.005) {
            return;
        }
        $old = number_format((float) $variant->cost_price, 2, '.', '');
        $variant->update(['cost_price' => $new]);

        $this->log->log(userId: $userId, action: 'cost_price_synced', entityType: 'ProductVariant', entityId: $variant->id,
            description: "Harga modal varian {$variant->sku} mengikuti resep v{$recipe->version}: {$old} -> ".number_format($new, 2, '.', '').'.',
            oldValues: ['cost_price' => $old], newValues: ['cost_price' => number_format($new, 2, '.', ''), 'recipe_id' => $recipe->id]);
    }

    private function validate(Product $product, array $data): void
    {
        $e = [];
        $yield = (float) ($data['yield'] ?? 0);
        if ($yield <= 0) {
            $e['yield'] = __('products.recipe_yield_invalid');
        }
        $from = isset($data['effective_from']) ? Carbon::parse($data['effective_from'])->startOfDay() : null;
        if ($from === null || $from->lt(Carbon::today()->subDays(self::MAX_BACKDATE_DAYS))) {
            $e['effective_from'] = __('products.recipe_date_invalid', ['days' => self::MAX_BACKDATE_DAYS]);
        }

        $variantIds = array_values(array_unique(array_map('intval', $data['variant_ids'] ?? [])));
        if ($variantIds === []) {
            $e['variant_ids'] = __('products.recipe_variants_required');
        } elseif (ProductVariant::query()->whereIn('id', $variantIds)->where('product_id', $product->id)->count() !== count($variantIds)) {
            $e['variant_ids'] = __('products.recipe_variants_foreign');
        }

        $lines = $data['lines'] ?? [];
        if ($lines === []) {
            $e['lines'] = __('products.recipe_lines_required');
        }
        foreach (array_values($lines) as $i => $l) {
            if ((float) ($l['quantity'] ?? 0) <= 0) {
                $e["lines.{$i}.quantity"] = __('products.recipe_quantity_invalid');
            }
            $shrink = (float) ($l['shrinkage_pct'] ?? 0);
            if ($shrink < 0 || $shrink > 99.99) {
                $e["lines.{$i}.shrinkage_pct"] = __('products.recipe_shrinkage_invalid');
            }
            if (! Material::query()->whereKey((int) ($l['material_id'] ?? 0))->exists()) {
                $e["lines.{$i}.material_id"] = __('products.recipe_material_unknown');
            }
        }

        if ($e !== []) {
            throw ValidationException::withMessages($e);
        }
    }

    private function num(mixed $v, int $dp): string
    {
        return number_format((float) $v, $dp, '.', '');
    }
}
