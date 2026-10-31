<?php

namespace App\Services;

use App\Exceptions\InputRejectedException;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;

/**
 * 028 US3 — SATU-SATUNYA tempat aturan modifier hidup (FR-022..FR-026):
 *  - grup yang berlaku untuk sebuah produk = grup produk itu ∪ grup kategorinya (kecuali yang DILEPAS item itu, 086),
 *    tanpa duplikat, hanya yang aktif dan punya opsi aktif;
 *  - pilihan klien divalidasi terhadap grup itu, BUKAN dipercaya apa adanya — id
 *    opsi dari grup lain/nonaktif/tak dikenal ditolak;
 *  - hasilnya snapshot yang disalin ke baris order, supaya edit menu di kemudian
 *    hari tidak pernah mengubah tab/penjualan yang sudah ada (Constitution IV).
 */
class ModifierResolver
{
    /**
     * 086 — SATU-SATUNYA pembaca grup yang diwarisi dari kategori: grup kategori dikurangi grup yang DILEPAS item ini
     * (`modifier_group_product_exclusions`). Dipakai resolver ini, halaman Produk (detail), dan daftar Produk, supaya tiga
     * pembaca tidak bisa berbeda pendapat tentang grup mana yang berlaku. Tautan item sendiri TIDAK disaring di sini:
     * tautan item menang atas pengecualian untuk grup yang sama (lihat `effectiveGroups`).
     *
     * @return Collection<int, ModifierGroup>
     */
    public function inheritedGroups(Product $product): Collection
    {
        // Tanpa `.options`: daftar Produk memuat `category.modifierGroups:id,name` saja dan tidak boleh memicu kueri opsi per produk.
        // Pemanggil yang butuh opsi (effectiveGroups) memuatnya sendiri.
        $product->loadMissing(['category.modifierGroups', 'excludedModifierGroups']);
        $excluded = $product->excludedModifierGroups->pluck('id');

        return ($product->category?->modifierGroups ?? collect())->reject(fn (ModifierGroup $g) => $excluded->contains($g->id))->values();
    }

    /**
     * @return Collection<int, ModifierGroup> grup aktif berurut (display_order, id), tiap grup membawa HANYA opsi aktif
     */
    public function effectiveGroups(Product $product): Collection
    {
        $product->loadMissing(['modifierGroups.options', 'category.modifierGroups.options', 'excludedModifierGroups']);

        return $product->modifierGroups
            ->concat($this->inheritedGroups($product))
            ->unique('id')
            ->filter(fn (ModifierGroup $g) => $g->is_active)
            ->map(function (ModifierGroup $g) {
                // Salinan dengan relasi `options` yang sudah difilter — tidak mengubah model yang di-cache relasi.
                $copy = $g->replicate()->setRelation('options', $g->options->where('is_active', true)->values());
                $copy->id = $g->id;

                // 043 — tautan item↔grup yang membawa aturannya sendiri MENIMPA aturan grup (wajib/min/maks/urutan).
                // `max_select` NULL pada tautan = tanpa batas atas. Grup yang datang dari kategori tidak punya aturan tautan.
                if ($g->pivot && (bool) ($g->pivot->has_rules ?? false)) {
                    $copy->is_required = (bool) $g->pivot->is_required;
                    $copy->min_select = (int) $g->pivot->min_select;
                    $copy->max_select = $g->pivot->max_select;
                    $copy->display_order = (int) ($g->pivot->display_order ?? $g->display_order);
                    $copy->setAttribute('link_override', true);
                }

                return $copy;
            })
            ->filter(fn (ModifierGroup $g) => $g->options->isNotEmpty())
            ->sortBy([['display_order', 'asc'], ['id', 'asc']])
            ->values();
    }

    /**
     * 084 — bentuk JSON grup efektif untuk klien (daftar produk `with_modifiers` dan `GET /variants/lookup`).
     * Satu tempat, supaya dua endpoint tidak pernah memberi bentuk yang berbeda untuk grup yang sama.
     *
     * @return array<int, array<string, mixed>>
     */
    public function present(Product $product): array
    {
        $groups = $this->effectiveGroups($product);
        // Satu eager-load untuk semua pilihan (bukan per pilihan): daftar POS memuat puluhan produk.
        (new \Illuminate\Database\Eloquent\Collection($groups->flatMap(fn (ModifierGroup $g) => $g->options)->all()))->loadMissing('materials.material');
        $book = app(\App\Support\OutletPriceBook::class);

        return $groups
            ->map(fn (ModifierGroup $g) => [
                'id' => $g->id, 'name' => $g->name, 'selection_type' => $g->selection_type,
                'is_required' => $g->is_required, 'min_select' => $g->min_select, 'max_select' => $g->max_select,
                // 2026-10-31 — tiap pilihan = 1 porsi (harga dasar per pilihan); klien memakainya untuk pratinjau harga.
                'portion_per_option' => (bool) $g->portion_per_option,
                'options' => $g->options->map(fn ($o) => [
                    'id' => $o->id, 'name' => $o->name, 'price_delta' => number_format((float) $o->price_delta, 2, '.', ''),
                ] + $this->optionStock($o, $book))->values(),
            ])->values()->all();
    }

    /**
     * 2026-10-31 (permintaan pemilik) — stok bahan sebuah pilihan di outlet pemesanan (popup modifier dan stok produk
     * bersumber modifier). `portions` = berapa porsi yang masih bisa dibuat (bilangan bulat ≥ 0, saldo minus = 0);
     * null = pilihan tanpa bahan (tak terbatas) atau tidak ada outlet pemesanan (daftar pengelolaan).
     *
     * @return array{materials: array<int, array<string, mixed>>, portions: int|null}
     */
    private function optionStock(\App\Models\ModifierOption $option, \App\Support\OutletPriceBook $book): array
    {
        $portions = null;
        $materials = $option->materials->map(function ($m) use ($book, &$portions) {
            $onHand = $book->orderingMaterialStockOrNull($m->material_id);
            if ($onHand !== null && (float) $m->quantity > 0) {
                $can = max(0, (int) floor($onHand / (float) $m->quantity + 1e-9));
                $portions = $portions === null ? $can : min($portions, $can);
            }

            return [
                'material_id' => $m->material_id, 'name' => $m->material?->name, 'unit' => $m->material?->unit,
                'quantity' => number_format((float) $m->quantity, 4, '.', ''),
                'on_hand' => $onHand === null ? null : number_format($onHand, 3, '.', ''),
            ];
        })->values()->all();

        return ['materials' => $materials, 'portions' => $portions];
    }

    /**
     * @param  array<int, int|string>  $optionIds
     * @param  float|null  $basePrice  harga dasar per unit; default harga varian SAAT INI
     *                                 (dipakai saat mengubah pilihan baris lama: dasar = harga saat baris ditambahkan)
     * @return array{snapshot: array<int, array<string, mixed>>, delta: float, unit_price: float}
     *
     * @throws InputRejectedException
     */
    public function resolve(ProductVariant $variant, array $optionIds, ?float $basePrice = null): array
    {
        $variant->loadMissing('product');
        $ids = array_values(array_unique(array_map('intval', $optionIds)));

        // Validasi SELALU memakai grup terbaru dari database: relasi yang sudah ter-cache pada
        // instance produk bisa basi (grup baru diubah/dinonaktifkan), dan aturan pilihan tidak
        // boleh bergantung pada apakah relasi kebetulan sudah dimuat. (Jalur daftar menu
        // sengaja memakai eager-load lewat effectiveGroups() langsung.)
        $variant->product->load(['modifierGroups.options', 'category.modifierGroups.options', 'excludedModifierGroups']);
        $groups = $this->effectiveGroups($variant->product);

        $valid = $groups->flatMap(fn (ModifierGroup $g) => $g->options)->keyBy('id');
        foreach ($ids as $id) {
            if (! $valid->has($id)) {
                throw $this->reject(__('restaurant.modifier_option_invalid'));
            }
        }

        $snapshot = [];
        $delta = 0.0;
        $base = $basePrice ?? (float) $variant->sell_price;

        foreach ($groups as $group) {
            $picked = $group->options->filter(fn ($o) => in_array($o->id, $ids, true));
            [$min, $max] = $this->range($group);

            if ($picked->count() < $min || ($max !== null && $picked->count() > $max)) {
                throw $this->reject(__('restaurant.modifier_rule_violated', [
                    'group' => $group->name,
                    'range' => $this->describeRange($min, $max),
                ]));
            }

            foreach ($picked->values() as $i => $option) {
                // 2026-10-31 — grup "tiap pilihan = 1 porsi": pilihan pertama tertutup harga dasar, tiap pilihan
                // berikutnya membawa SATU harga dasar lagi di price_delta snapshot-nya. Dengan begitu
                // `unit_price = dasar + Σ price_delta` tetap benar bagi semua pembaca (struk, ubah baris tab).
                $optionDelta = (float) $option->price_delta + ($group->portion_per_option && $i > 0 ? $base : 0.0);
                $snapshot[] = [
                    'group_id' => $group->id,
                    'group' => $group->name,
                    'option_id' => $option->id,
                    'option' => $option->name,
                    'price_delta' => number_format($optionDelta, 2, '.', ''),
                ];
                $delta += $optionDelta;
            }
        }

        $delta = round($delta, 2);
        $unit = round($base + $delta, 2);

        if ($unit < 0) {
            throw $this->reject(__('restaurant.modifier_price_negative'));
        }

        return ['snapshot' => $snapshot, 'delta' => $delta, 'unit_price' => $unit];
    }

    /** @return array{0: int, 1: int|null} [min, max] — max null = tanpa batas atas */
    private function range(ModifierGroup $group): array
    {
        // 043 — aturan tautan berlaku apa adanya, terlepas dari jenis pilihan grup.
        if ($group->getAttribute('link_override')) {
            $min = $group->is_required ? max(1, (int) $group->min_select) : (int) $group->min_select;

            return [$min, $group->max_select === null ? null : (int) $group->max_select];
        }

        if ($group->selection_type === ModifierGroup::SINGLE) {
            return [$group->is_required ? 1 : 0, 1];
        }

        $min = $group->is_required ? max(1, (int) $group->min_select) : (int) $group->min_select;

        return [$min, $group->max_select === null ? null : (int) $group->max_select];
    }

    private function describeRange(int $min, ?int $max): string
    {
        return match (true) {
            $max === null => "≥{$min}",
            $min === $max => (string) $min,
            default => "{$min}–{$max}",
        };
    }

    private function reject(string $message): InputRejectedException
    {
        return InputRejectedException::withMessages(['modifier_option_ids' => [$message]]);
    }
}
