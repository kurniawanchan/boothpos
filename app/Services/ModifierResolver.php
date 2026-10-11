<?php

namespace App\Services;

use App\Exceptions\InputRejectedException;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;

/**
 * 028 US3 — SATU-SATUNYA tempat aturan modifier hidup (FR-022..FR-026):
 *  - grup yang berlaku untuk sebuah produk = grup produk itu ∪ grup kategorinya,
 *    tanpa duplikat, hanya yang aktif dan punya opsi aktif;
 *  - pilihan klien divalidasi terhadap grup itu, BUKAN dipercaya apa adanya — id
 *    opsi dari grup lain/nonaktif/tak dikenal ditolak;
 *  - hasilnya snapshot yang disalin ke baris order, supaya edit menu di kemudian
 *    hari tidak pernah mengubah tab/penjualan yang sudah ada (Constitution IV).
 */
class ModifierResolver
{
    /**
     * @return Collection<int, ModifierGroup> grup aktif berurut (display_order, id), tiap grup membawa HANYA opsi aktif
     */
    public function effectiveGroups(Product $product): Collection
    {
        $product->loadMissing(['modifierGroups.options', 'category.modifierGroups.options']);

        return $product->modifierGroups
            ->concat($product->category?->modifierGroups ?? [])
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
        $variant->product->load(['modifierGroups.options', 'category.modifierGroups.options']);
        $groups = $this->effectiveGroups($variant->product);

        $valid = $groups->flatMap(fn (ModifierGroup $g) => $g->options)->keyBy('id');
        foreach ($ids as $id) {
            if (! $valid->has($id)) {
                throw $this->reject(__('restaurant.modifier_option_invalid'));
            }
        }

        $snapshot = [];
        $delta = 0.0;

        foreach ($groups as $group) {
            $picked = $group->options->filter(fn ($o) => in_array($o->id, $ids, true));
            [$min, $max] = $this->range($group);

            if ($picked->count() < $min || ($max !== null && $picked->count() > $max)) {
                throw $this->reject(__('restaurant.modifier_rule_violated', [
                    'group' => $group->name,
                    'range' => $this->describeRange($min, $max),
                ]));
            }

            foreach ($picked as $option) {
                $snapshot[] = [
                    'group_id' => $group->id,
                    'group' => $group->name,
                    'option_id' => $option->id,
                    'option' => $option->name,
                    'price_delta' => number_format((float) $option->price_delta, 2, '.', ''),
                ];
                $delta += (float) $option->price_delta;
            }
        }

        $delta = round($delta, 2);
        $unit = round(($basePrice ?? (float) $variant->sell_price) + $delta, 2);

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
