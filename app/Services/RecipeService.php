<?php

namespace App\Services;

use App\Exceptions\BomRuleException;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeBlock;
use App\Models\User;
use App\Support\BusinessDay;
use App\Support\ModeGate;
use App\Support\PermissionCatalog;
use App\Support\RecipeCost;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Resep menu/varian (043, diubah 089).
 *
 * 089 (keputusan pemilik 2026-11-02) — resep TIDAK berversi dan SEMUA resep aktif sebuah varian dijumlahkan:
 * saat pesanan selesai (potong stok bahan, ModifierMaterialConsumption::needFor) dan untuk harga modal varian.
 * Resep diubah di tempat (`update`) atau dihapus satuan (`removeOne`); sisa versi lama hasil migrasi diarsipkan
 * (baca-saja, tidak berlaku). Resep aktif = tidak arsip dan `effective_from` ≤ hari bisnis ini.
 *
 * - Satu varian punya SATU sumber biaya: resep ATAU BOM-selesai. Memilih varian BOM-selesai untuk resep
 *   ditolak 409 `cost_source_conflict`, dan sebaliknya (VariantBomService::complete memanggil `isRecipeDriven`).
 * - Service ini penulis SATU-SATUNYA `cost_price` varian yang digerakkan resep: harga modal = JUMLAH biaya porsi
 *   semua resep aktif. Bila salah satu resep tak lengkap (bahan tanpa harga), `cost_price` tidak ditulis.
 *   `order_items.cost_price` yang sudah tercatat tidak pernah berubah. Biaya resep TIDAK ditambahkan lagi ke baris
 *   pesanan saat bahan dipotong (sudah ada di `cost_price` varian) — mencegah biaya ganda.
 * - Pemotongan stok bahan dilakukan ModifierMaterialConsumption (satu penerjemah pesanan → bahan); service ini hanya
 *   menyediakan resep aktif lewat `activeRecipesFor*`. Tanpa konversi satuan (satuan baris = satuan bahan).
 */
class RecipeService
{
    /** Tanggal berlaku paling jauh sekian hari ke belakang (mencegah resep "diam-diam" berlaku surut). */
    public const MAX_BACKDATE_DAYS = 30;

    public function __construct(private ActivityLogger $log) {}

    // --- tulis -------------------------------------------------------------------------------------

    /**
     * @param  array{name: string, yield: float|int|string, effective_from: string, variant_ids: list<int>, lines: list<array<string, mixed>>, note?: string|null}  $data
     *
     * @throws ValidationException|BomRuleException
     */
    public function create(Product $product, array $data, User $by): Recipe
    {
        $this->validate($product, $data);

        return DB::transaction(function () use ($product, $data, $by) {
            Product::query()->whereKey($product->id)->lockForUpdate()->first();

            $blocks = $this->blocksFrom($data);
            $variants = $this->lockVariants($product, array_column($blocks, 'variant_id'));

            $recipe = Recipe::create([
                'product_id' => $product->id,
                'name' => trim($data['name']),
                'yield' => $data['yield'],
                'effective_from' => $data['effective_from'],
                'note' => $data['note'] ?? null,
                'created_by' => $by->id,
            ]);
            $this->writeBody($recipe, $blocks);

            $this->log->log(userId: $by->id, action: 'recipe_created', entityType: 'Product', entityId: $product->id,
                description: "Resep {$recipe->name} ({$product->name}) dibuat.", newValues: ['recipe_id' => $recipe->id, 'name' => $recipe->name]);

            $this->applyDue($by->id);

            return $recipe->fresh(['blocks.lines.material', 'blocks.option', 'variants']);
        });
    }

    /**
     * 089 — mengubah resep DI TEMPAT (tanpa versi). Resep arsip (sisa versi lama) baca-saja: 409 `recipe_archived`.
     * Harga modal varian yang terkait (lama maupun baru) dihitung ulang dalam transaksi yang sama.
     *
     * @param  array{name: string, yield: float|int|string, effective_from: string, variant_ids: list<int>, lines: list<array<string, mixed>>, note?: string|null}  $data
     *
     * @throws ValidationException|BomRuleException
     */
    public function update(Recipe $recipe, array $data, User $by): Recipe
    {
        if ($recipe->archived_at !== null) {
            throw new BomRuleException(__('products.recipe_archived'), 'recipe_archived');
        }
        $product = $recipe->product()->first();
        $this->validate($product, $data, $recipe);

        return DB::transaction(function () use ($recipe, $product, $data, $by) {
            Product::query()->whereKey($product->id)->lockForUpdate()->first();
            $recipe->refresh();
            $recipe->load(['blocks.lines', 'variants:product_variants.id']);

            $before = $this->snapshot($recipe);
            $oldVariantIds = $recipe->variants->pluck('id')->all();
            $blocks = $this->blocksFrom($data);
            $variants = $this->lockVariants($product, array_column($blocks, 'variant_id'));

            $recipe->update([
                'name' => trim($data['name']), 'yield' => $data['yield'], 'effective_from' => $data['effective_from'],
                'note' => $data['note'] ?? null,
                // Isi berubah → biayanya perlu diterapkan lagi saat berlaku.
                'cost_applied_at' => null,
            ]);
            $recipe->blocks()->delete(); // baris bahan ikut terhapus (CASCADE)
            $this->writeBody($recipe, $blocks);
            $recipe->load(['blocks.lines', 'variants:product_variants.id']);

            $this->log->log(userId: $by->id, action: 'recipe_updated', entityType: 'Product', entityId: $product->id,
                description: "Resep {$recipe->name} ({$product->name}) diubah.", oldValues: $before, newValues: $this->snapshot($recipe));

            $this->resyncVariants(array_merge($oldVariantIds, $variants->pluck('id')->all()), $by->id);
            $this->applyDue($by->id);

            return $recipe->fresh(['blocks.lines.material', 'blocks.option', 'variants']);
        });
    }

    /**
     * Menghapus SATU resep. Isinya disimpan di log aktivitas karena barisnya ikut terhapus (baris resep dan tautan
     * varian CASCADE). Varian yang tak lagi punya resep aktif berhenti digerakkan resep; harga modal terakhir
     * dibiarkan dan `order_items.cost_price` yang sudah tercatat tidak berubah.
     */
    public function removeOne(Recipe $recipe, User $by): void
    {
        DB::transaction(function () use ($recipe, $by) {
            $product = $recipe->product()->first();
            Product::query()->whereKey($product->id)->lockForUpdate()->first();
            $recipe->load(['blocks.lines', 'variants:product_variants.id']);
            $variantIds = $recipe->variants->pluck('id')->all();

            $this->log->log(userId: $by->id, action: 'recipe_removed', entityType: 'Product', entityId: $product->id,
                description: "Resep {$recipe->name} ({$product->name}) dihapus.", oldValues: ['recipes' => [$this->snapshot($recipe)]]);
            $recipe->delete();

            $this->resyncVariants($variantIds, $by->id);
        });
    }

    /**
     * 2026-10-31 (permintaan pemilik: "bisa hapus resep") — menghapus SEMUA resep sebuah item (jalur lama, tetap ada).
     * Isi tiap resep disimpan di log aktivitas.
     *
     * @return int banyaknya resep yang dihapus (0 = item tidak punya resep)
     */
    public function remove(Product $product, User $by): int
    {
        return DB::transaction(function () use ($product, $by) {
            Product::query()->whereKey($product->id)->lockForUpdate()->first();
            $recipes = Recipe::query()->where('product_id', $product->id)->with(['blocks.lines', 'variants:product_variants.id'])->orderBy('id')->get();
            if ($recipes->isEmpty()) {
                return 0;
            }

            $this->log->log(
                userId: $by->id, action: 'recipe_removed', entityType: 'Product', entityId: $product->id,
                description: "Resep {$product->name} dihapus ({$recipes->count()} resep).",
                oldValues: ['recipes' => $recipes->map(fn (Recipe $r) => $this->snapshot($r))->all()],
            );
            Recipe::query()->whereKey($recipes->pluck('id'))->delete();

            return $recipes->count();
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
                $cost = $this->variantCost($variant);
                if ($cost === null) {
                    continue;
                }
                if ($cost['incomplete']) {
                    $skipped[] = ['variant_id' => $variant->id, 'sku' => $variant->sku, 'reason' => 'incomplete'];

                    continue;
                }
                $this->writeCost($variant, $cost['portion_cost'], $by->id, $cost['count']);
                $updated[] = ['variant_id' => $variant->id, 'sku' => $variant->sku, 'cost_price' => number_format($cost['portion_cost'], 2, '.', '')];
            }

            Recipe::query()->active()->where('product_id', $product->id)->whereNull('cost_applied_at')->update(['cost_applied_at' => now()]);

            return ['updated' => $updated, 'skipped' => $skipped];
        });
    }

    /**
     * Menerapkan biaya resep yang SUDAH berlaku tetapi belum diterapkan (tanggal berlaku di hari ini atau yang lalu,
     * atau yang tanggalnya baru tiba). Tanpa penjadwal: dipanggil malas di titik masuk. Harga modal sebuah varian =
     * jumlah semua resep aktifnya, jadi yang dihitung ulang adalah VARIAN yang tertaut ke resep jatuh tempo itu.
     */
    public function applyDue(?int $userId = null): void
    {
        $due = Recipe::query()->active()->whereNull('cost_applied_at')->with('variants:product_variants.id')->get();
        if ($due->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($due, $userId) {
            $this->resyncVariants($due->flatMap(fn (Recipe $r) => $r->variants->pluck('id'))->unique()->all(), $userId);
            Recipe::query()->whereKey($due->pluck('id'))->update(['cost_applied_at' => now()]);
        });
    }

    /** `applyDue()` paling sering sekali per 10 detik per mode data. */
    public function applyDueCached(): void
    {
        if (Cache::add('recipe.apply_due:'.ModeGate::current(), 1, 10)) {
            $this->applyDue();
        }
    }

    // --- baca --------------------------------------------------------------------------------------

    /**
     * Semua resep aktif sebuah varian (089): tidak arsip, sudah berlaku. Semuanya dijumlahkan saat penjualan.
     *
     * @return Collection<int, Recipe>
     */
    public function activeRecipesFor(ProductVariant $variant): Collection
    {
        return Recipe::query()->active()
            ->whereHas('variants', fn ($q) => $q->where('product_variants.id', $variant->id))
            ->with(['blocks.lines.material', 'blocks.option'])
            ->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Resep aktif untuk BANYAK varian dengan dua kueri (tanpa N+1): dipakai saat pesanan selesai.
     *
     * @param  list<int>  $variantIds
     * @return array<int, Collection<int, Recipe>> variant_id => resep aktif (varian tanpa resep tidak ada)
     */
    public function activeRecipesForVariants(array $variantIds): array
    {
        $variantIds = array_values(array_unique(array_map('intval', $variantIds)));
        if ($variantIds === []) {
            return [];
        }

        $links = DB::table('recipe_variants')->whereIn('variant_id', $variantIds)->get(['recipe_id', 'variant_id']);
        if ($links->isEmpty()) {
            return [];
        }
        $recipes = Recipe::query()->active()->whereIn('id', $links->pluck('recipe_id')->unique())->with(['blocks.lines.material', 'blocks.option'])->get()->keyBy('id');

        $out = [];
        foreach ($links as $link) {
            if ($recipes->has($link->recipe_id)) {
                $out[(int) $link->variant_id][] = $recipes[$link->recipe_id];
            }
        }

        return array_map(fn (array $list) => collect($list), $out);
    }

    public function isRecipeDriven(ProductVariant $variant): bool
    {
        return Recipe::query()->active()->whereHas('variants', fn ($q) => $q->where('product_variants.id', $variant->id))->exists();
    }

    /**
     * Harga modal varian dari resep: JUMLAH biaya porsi semua resep aktif. null = varian tanpa resep aktif.
     *
     * @param  Collection<int, Recipe>|null  $recipes  resep aktif yang sudah dimuat (hindari kueri ulang)
     * @return array{portion_cost: float, incomplete: bool, count: int}|null
     */
    public function variantCost(ProductVariant $variant, ?Collection $recipes = null): ?array
    {
        $recipes ??= $this->activeRecipesFor($variant);
        if ($recipes->isEmpty()) {
            return null;
        }

        $sum = 0.0;
        $incomplete = false;
        $count = 0;
        foreach ($recipes as $recipe) {
            // Harga modal = resep DASAR varian ini (blok tanpa pilihan modifier). Blok bermodifier hanya terpakai bila
            // pilihannya dipesan; biayanya ditambahkan ke baris pesanan oleh ModifierMaterialConsumption, bukan di sini.
            $block = $this->baseBlock($recipe, $variant->id);
            if ($block === null) {
                continue;
            }
            $c = $this->cost($recipe, (float) $variant->sell_price, $block);
            $sum += $c['portion_cost'];
            $incomplete = $incomplete || $c['incomplete'];
            $count++;
        }
        if ($count === 0) {
            return null;
        }

        return ['portion_cost' => round($sum, 4), 'incomplete' => $incomplete, 'count' => $count];
    }

    /** @return array{line_costs: list<float|null>, portion_cost: float, incomplete: bool, food_cost_pct: float|null, gross_margin: float|null, unit_costs: list<float|null>} */
    public function cost(Recipe $recipe, ?float $basePrice, ?RecipeBlock $block = null): array
    {
        $recipe->loadMissing('blocks.lines.material');
        // Tanpa blok: resep dasar blok pertama (bentuk lama satu tabel); tanpa blok sama sekali = biaya nol.
        $block ??= $recipe->blocks->first(fn ($b) => $b->modifier_option_id === null) ?? $recipe->blocks->first();
        $lines = $block ? $block->lines : collect();
        $units = $lines->map(fn ($l) => $this->unitCost($l->material))->all();
        $inputs = $lines->values()->map(fn ($l, $i) => ['quantity' => $l->quantity, 'shrinkage_pct' => $l->shrinkage_pct, 'unit_cost' => $units[$i]])->all();

        return RecipeCost::compute($inputs, (float) $recipe->yield, $basePrice) + ['unit_costs' => array_values($units)];
    }

    /** Blok resep DASAR (tanpa pilihan modifier) sebuah varian, atau null. */
    public function baseBlock(Recipe $recipe, int $variantId): ?RecipeBlock
    {
        return $recipe->blocks->first(fn ($b) => (int) $b->variant_id === $variantId && $b->modifier_option_id === null);
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

    /** @return list<array<string, mixed>> semua resep sebuah produk (termasuk arsip), diurutkan menurut nama */
    public function list(Product $product, bool $mayCost): array
    {
        $head = $product->variants()->orderBy('id')->first();
        $recipes = Recipe::query()->where('product_id', $product->id)->with(['variants', 'blocks.lines.material'])->orderBy('name')->orderBy('id')->get();

        return $recipes->map(function (Recipe $r) use ($mayCost, $head) {
            $row = [
                'id' => $r->id, 'name' => $r->name, 'yield' => $this->num($r->yield, 3),
                'effective_from' => $r->effective_from->toDateString(), 'note' => $r->note,
                'archived' => $r->archived_at !== null,
                'created_by' => $r->creator?->name, 'created_at' => $r->created_at?->toIso8601String(),
                'variants' => $r->variants->map(fn ($v) => ['id' => $v->id, 'sku' => $v->sku, 'name' => $v->variant_name])->values()->all(),
                'block_count' => $r->blocks->count(),
                'is_active_now' => $this->isActiveNow($r),
            ];
            if ($mayCost) {
                $c = $this->cost($r, $head ? (float) $head->sell_price : null);
                $row += ['portion_cost' => $this->num($c['portion_cost'], 2), 'incomplete' => $c['incomplete'], 'food_cost_pct' => $c['food_cost_pct']];
            }

            return $row;
        })->all();
    }

    /**
     * @return array<string, mixed> satu resep lengkap. `blocks` = tabel bahan per (varian, pilihan modifier); `lines` dan
     *                              biaya tingkat atas = blok dasar pertama (bentuk lama satu tabel, dipertahankan untuk pembaca lama).
     */
    public function present(Recipe $recipe, bool $mayCost): array
    {
        $recipe->loadMissing(['blocks.lines.material', 'blocks.option.group', 'variants']);
        $head = $recipe->variants->sortBy('id')->first();

        $blocks = $recipe->blocks->values()->map(function (RecipeBlock $b) use ($recipe, $mayCost) {
            $variant = $recipe->variants->firstWhere('id', $b->variant_id);
            $c = $mayCost ? $this->cost($recipe, $variant ? (float) $variant->sell_price : null, $b) : null;

            return [
                'variant_id' => $b->variant_id,
                'modifier_option_id' => $b->modifier_option_id,
                'modifier' => $b->option ? ['id' => $b->option->id, 'name' => $b->option->name, 'group' => $b->option->group?->name] : null,
                'lines' => $this->presentLines($b, $c),
            ] + ($c ? [
                'portion_cost' => $this->num($c['portion_cost'], 2), 'incomplete' => $c['incomplete'],
                'food_cost_pct' => $c['food_cost_pct'], 'gross_margin' => $c['gross_margin'] === null ? null : $this->num($c['gross_margin'], 2),
            ] : []);
        })->all();

        $first = $recipe->blocks->first(fn ($b) => $b->modifier_option_id === null) ?? $recipe->blocks->first();
        $c = $mayCost ? $this->cost($recipe, $head ? (float) $head->sell_price : null, $first) : null;

        return [
            'id' => $recipe->id, 'product_id' => $recipe->product_id, 'name' => $recipe->name, 'archived' => $recipe->archived_at !== null,
            'yield' => $this->num($recipe->yield, 3), 'effective_from' => $recipe->effective_from->toDateString(), 'note' => $recipe->note,
            'variants' => $recipe->variants->map(fn ($v) => ['id' => $v->id, 'sku' => $v->sku, 'name' => $v->variant_name])->values()->all(),
            'blocks' => $blocks,
            'lines' => $first ? $this->presentLines($first, $c) : [],
        ] + ($c ? [
            'portion_cost' => $this->num($c['portion_cost'], 2), 'incomplete' => $c['incomplete'],
            'food_cost_pct' => $c['food_cost_pct'], 'gross_margin' => $c['gross_margin'] === null ? null : $this->num($c['gross_margin'], 2),
        ] : []);
    }

    /** @param  array<string, mixed>|null  $c  hasil cost() untuk blok ini (null = tanpa izin biaya) */
    private function presentLines(RecipeBlock $block, ?array $c): array
    {
        return $block->lines->values()->map(function ($l, $i) use ($c) {
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
    }

    /** Boleh melihat biaya: menu produk/laporan DAN (izin ubah resep atau pemilik/admin lewat bawaan peran). */
    public function mayCost(User $user): bool
    {
        return ($user->canAccessMenu('products') || $user->canAccessMenu('reports')) && $user->hasPermission(PermissionCatalog::RECIPE_EDIT);
    }

    // --- internal ----------------------------------------------------------------------------------

    private function isActiveNow(Recipe $r): bool
    {
        return $r->archived_at === null && $r->effective_from->toDateString() <= BusinessDay::today() && $r->variants->isNotEmpty();
    }

    /**
     * Kunci varian yang dipilih dan tolak yang memakai BOM-selesai sebagai sumber biaya (409).
     *
     * @param  list<int>  $variantIds
     * @return Collection<int, ProductVariant>
     *
     * @throws BomRuleException
     */
    private function lockVariants(Product $product, array $variantIds): Collection
    {
        $variants = ProductVariant::query()->whereIn('id', $variantIds)->where('product_id', $product->id)->lockForUpdate()->get();
        $conflict = $variants->where('bom_complete', true);
        if ($conflict->isNotEmpty()) {
            throw new BomRuleException(
                __('products.recipe_conflict_bom', ['skus' => $conflict->pluck('sku')->implode(', ')]),
                'cost_source_conflict',
                ['variants' => $conflict->map(fn ($v) => ['id' => $v->id, 'sku' => $v->sku])->values()->all()],
            );
        }

        return $variants;
    }

    /**
     * Normalisasi isi permintaan menjadi blok: `blocks` (varian + pilihan modifier + bahan) atau bentuk lama
     * `variant_ids` + `lines` (satu blok dasar per varian dengan bahan yang sama).
     *
     * @return list<array{variant_id: int, modifier_option_id: int|null, lines: list<array<string, mixed>>}>
     */
    private function blocksFrom(array $data): array
    {
        if (array_key_exists('blocks', $data)) {
            return array_values(array_map(fn ($b) => [
                'variant_id' => (int) ($b['variant_id'] ?? 0),
                'modifier_option_id' => ! empty($b['modifier_option_id']) ? (int) $b['modifier_option_id'] : null,
                'lines' => array_values($b['lines'] ?? []),
            ], $data['blocks'] ?? []));
        }

        $lines = array_values($data['lines'] ?? []);

        return array_map(fn ($id) => ['variant_id' => (int) $id, 'modifier_option_id' => null, 'lines' => $lines],
            array_values(array_unique(array_map('intval', $data['variant_ids'] ?? []))));
    }

    /** @param list<array{variant_id: int, modifier_option_id: int|null, lines: list<array<string, mixed>>}> $blocks */
    private function writeBody(Recipe $recipe, array $blocks): void
    {
        $recipe->variants()->sync(array_values(array_unique(array_column($blocks, 'variant_id'))));
        foreach (array_values($blocks) as $bi => $b) {
            $block = $recipe->blocks()->create(['variant_id' => $b['variant_id'], 'modifier_option_id' => $b['modifier_option_id'], 'display_order' => $bi + 1]);
            foreach (array_values($b['lines']) as $i => $l) {
                $block->lines()->create([
                    'recipe_id' => $recipe->id, 'material_id' => (int) $l['material_id'], 'quantity' => $l['quantity'],
                    'shrinkage_pct' => $l['shrinkage_pct'] ?? 0, 'display_order' => $i + 1,
                ]);
            }
        }
    }

    /** @return array<string, mixed> isi resep untuk log aktivitas (barisnya bisa terhapus) */
    private function snapshot(Recipe $r): array
    {
        return [
            'recipe_id' => $r->id, 'name' => $r->name, 'yield' => (float) $r->yield, 'effective_from' => $r->effective_from?->toDateString(),
            'variant_ids' => $r->variants->pluck('id')->all(),
            'blocks' => $r->blocks->map(fn ($b) => [
                'variant_id' => $b->variant_id, 'modifier_option_id' => $b->modifier_option_id,
                'lines' => $b->lines->map(fn ($l) => ['material_id' => $l->material_id, 'quantity' => (float) $l->quantity, 'shrinkage_pct' => (float) $l->shrinkage_pct])->all(),
            ])->all(),
        ];
    }

    /**
     * Hitung ulang harga modal varian-varian ini dari resep aktifnya (jumlah). Varian tanpa resep aktif dibiarkan
     * (berhenti digerakkan resep, harga terakhir tetap); biaya tak lengkap dilewati — aturan lama satu resep.
     *
     * @param  list<int>  $variantIds
     */
    private function resyncVariants(array $variantIds, ?int $userId): void
    {
        $variantIds = array_values(array_unique(array_map('intval', $variantIds)));
        $byVariant = $this->activeRecipesForVariants($variantIds);

        foreach (ProductVariant::query()->whereIn('id', $variantIds)->get() as $variant) {
            $recipes = $byVariant[$variant->id] ?? null;
            $cost = $recipes ? $this->variantCost($variant, $recipes) : null;
            if ($cost !== null && ! $cost['incomplete']) {
                $this->writeCost($variant, $cost['portion_cost'], $userId, $cost['count']);
            }
        }
    }

    private function writeCost(ProductVariant $variant, float $portionCost, ?int $userId, int $recipeCount): void
    {
        $new = round($portionCost, 2);
        if (abs((float) $variant->cost_price - $new) < 0.005) {
            return;
        }
        $old = number_format((float) $variant->cost_price, 2, '.', '');
        $variant->update(['cost_price' => $new]);

        $this->log->log(userId: $userId, action: 'cost_price_synced', entityType: 'ProductVariant', entityId: $variant->id,
            description: "Harga modal varian {$variant->sku} mengikuti {$recipeCount} resep aktif: {$old} -> ".number_format($new, 2, '.', '').'.',
            oldValues: ['cost_price' => $old], newValues: ['cost_price' => number_format($new, 2, '.', ''), 'recipe_count' => $recipeCount]);
    }

    private function validate(Product $product, array $data, ?Recipe $existing = null): void
    {
        $e = [];
        if (trim((string) ($data['name'] ?? '')) === '' || mb_strlen(trim((string) $data['name'])) > 100) {
            $e['name'] = __('products.recipe_name_required');
        }
        $yield = (float) ($data['yield'] ?? 0);
        if ($yield <= 0) {
            $e['yield'] = __('products.recipe_yield_invalid');
        }
        $from = isset($data['effective_from']) ? Carbon::parse($data['effective_from'])->startOfDay() : null;
        // Resep yang sudah ada boleh disimpan dengan tanggal berlakunya yang lama; hanya tanggal BARU yang dibatasi.
        $unchanged = $existing !== null && $from !== null && $from->toDateString() === $existing->effective_from?->toDateString();
        if ($from === null || (! $unchanged && $from->lt(Carbon::parse(BusinessDay::today())->subDays(self::MAX_BACKDATE_DAYS)))) {
            $e['effective_from'] = __('products.recipe_date_invalid', ['days' => self::MAX_BACKDATE_DAYS]);
        }

        $legacy = ! array_key_exists('blocks', $data);
        $blocks = $this->blocksFrom($data);
        $variantIds = array_values(array_unique(array_column($blocks, 'variant_id')));
        $key = fn (int $i, string $field) => $legacy ? $field : "blocks.{$i}.{$field}";

        if ($blocks === []) {
            $e[$legacy ? 'variant_ids' : 'blocks'] = __('products.recipe_variants_required');
        } elseif (in_array(0, $variantIds, true) || ProductVariant::query()->whereIn('id', $variantIds)->where('product_id', $product->id)->count() !== count($variantIds)) {
            $e[$legacy ? 'variant_ids' : 'blocks'] = __('products.recipe_variants_foreign');
        }

        // Pilihan modifier harus milik grup yang berlaku untuk produk ini; pasangan (varian, pilihan) tidak boleh kembar.
        $allowed = $blocks === [] ? collect() : app(ModifierResolver::class)->effectiveGroups($product)->flatMap(fn ($g) => $g->options->pluck('id'))->flip();
        $seen = [];
        foreach ($blocks as $i => $b) {
            if ($b['modifier_option_id'] !== null && ! $allowed->has($b['modifier_option_id'])) {
                $e[$key($i, 'modifier_option_id')] = __('products.recipe_modifier_foreign');
            }
            $pair = $b['variant_id'].':'.($b['modifier_option_id'] ?? 'base');
            if (isset($seen[$pair])) {
                $e[$key($i, 'modifier_option_id')] = __('products.recipe_block_duplicate');
            }
            $seen[$pair] = true;

            if ($b['lines'] === []) {
                $e[$legacy ? 'lines' : "blocks.{$i}.lines"] = __('products.recipe_lines_required');
            }
            foreach ($b['lines'] as $j => $l) {
                $p = $legacy ? "lines.{$j}" : "blocks.{$i}.lines.{$j}";
                if ((float) ($l['quantity'] ?? 0) <= 0) {
                    $e["{$p}.quantity"] = __('products.recipe_quantity_invalid');
                }
                $shrink = (float) ($l['shrinkage_pct'] ?? 0);
                if ($shrink < 0 || $shrink > 99.99) {
                    $e["{$p}.shrinkage_pct"] = __('products.recipe_shrinkage_invalid');
                }
                if (! Material::query()->whereKey((int) ($l['material_id'] ?? 0))->exists()) {
                    $e["{$p}.material_id"] = __('products.recipe_material_unknown');
                }
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
