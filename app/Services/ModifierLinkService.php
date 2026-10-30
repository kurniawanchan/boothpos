<?php

namespace App\Services;

use App\Models\MenuChange;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\User;
use App\Support\MenuSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 043-products-page-redesign — tautan item↔grup modifier dengan aturan PER ITEM (wajib, min, maks, urutan).
 * Mengganti seluruh tautan item itu dalam satu transaksi. Isi grup (opsi) tidak pernah diubah di sini —
 * tetap di layar Setup restoran. Dengan rilis AKTIF, perubahan diantrekan sebagai `menu_change` (`modifier_link`)
 * dan diterapkan lewat `applyLinks()` yang sama saat rilis terbit (research.md D2, D10).
 */
class ModifierLinkService
{
    public function __construct(private ActivityLogger $log) {}

    /**
     * @param  list<array{group_id: int, is_required?: bool, min_select?: int, max_select?: int|null, display_order?: int}>  $links
     * @param  list<int>|null  $excluded  086 — grup kategori yang DILEPAS item ini. null = pengecualian tidak berubah (klien lama
     *                                    yang tak mengirim kuncinya tidak boleh menghapusnya); [] = semua dipulihkan.
     * @return array{applied: bool, change: MenuChange|null}
     *
     * @throws ValidationException
     */
    public function replace(Product $product, array $links, User $by, ?array $excluded = null): array
    {
        $clean = $this->validate($links, $excluded);
        $excluded = $excluded === null ? null : array_values(array_map('intval', $excluded));

        return DB::transaction(function () use ($product, $clean, $by, $excluded) {
            if (MenuSettings::releaseEnabled()) {
                $change = MenuChange::create([
                    'kind' => MenuChange::KIND_MODIFIER_LINK, 'subject_type' => 'product', 'subject_id' => $product->id,
                    'payload' => ['product_name' => $product->name, 'links' => $clean] + ($excluded === null ? [] : ['excluded_group_ids' => $excluded]),
                    'status' => MenuChange::PENDING, 'requested_by' => $by->id,
                ]);
                $this->log->log(userId: $by->id, action: 'menu_change_requested', entityType: 'Product', entityId: $product->id, description: "Tautan modifier {$product->name} diajukan ke rilis.", newValues: ['change_id' => $change->id]);

                return ['applied' => false, 'change' => $change];
            }

            $this->applyLinks($product, $clean, $by, $excluded);

            return ['applied' => true, 'change' => null];
        });
    }

    /** Penerapan langsung (juga dipanggil ModifierLinkApplier). */
    public function applyLinks(Product $product, array $links, User $by, ?array $excluded = null): void
    {
        $excludedBefore = $product->excludedModifierGroups()->pluck('modifier_groups.id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $before = $product->modifierGroups()->get()->map(fn ($g) => ['group_id' => $g->id, 'is_required' => (bool) $g->pivot->is_required, 'min_select' => $g->pivot->min_select, 'max_select' => $g->pivot->max_select])->all();

        $product->modifierGroups()->sync(collect($links)->mapWithKeys(fn ($l) => [$l['group_id'] => [
            'has_rules' => true, 'is_required' => $l['is_required'], 'min_select' => $l['min_select'],
            'max_select' => $l['max_select'], 'display_order' => $l['display_order'],
        ]])->all());

        // 086 — pengecualian hanya ditulis bila dikirim; tabelnya terpisah dari tautan item (lihat migrasinya). Hanya baris
        // BARU yang mendapat `created_at`, supaya "kapan dilepas" tidak tertimpa saat disimpan ulang.
        if ($excluded !== null) {
            $wanted = array_values(array_unique(array_map('intval', $excluded)));
            $gone = array_values(array_diff($excludedBefore, $wanted));
            $fresh = array_values(array_diff($wanted, $excludedBefore));
            if ($gone !== []) {
                $product->excludedModifierGroups()->detach($gone);
            }
            if ($fresh !== []) {
                $product->excludedModifierGroups()->attach(collect($fresh)->mapWithKeys(fn ($id) => [$id => ['created_at' => now()]])->all());
            }
            $product->unsetRelation('excludedModifierGroups');
        }

        $this->log->log(
            userId: $by->id, action: 'modifier_links_updated', entityType: 'Product', entityId: $product->id, description: "Tautan modifier {$product->name} diubah.",
            oldValues: ['links' => $before] + ($excluded === null ? [] : ['excluded_group_ids' => $excludedBefore]),
            newValues: ['links' => $links] + ($excluded === null ? [] : ['excluded_group_ids' => collect($excluded)->map(fn ($id) => (int) $id)->sort()->values()->all()]),
        );
    }

    /** @return list<array{group_id: int, is_required: bool, min_select: int, max_select: int|null, display_order: int}> */
    private function validate(array $links, ?array $excluded = null): array
    {
        $errors = [];
        $clean = [];
        $seen = [];

        // 086 — tiap id pengecualian harus grup yang ada, dan tidak ganda.
        $seenExcluded = [];
        foreach (array_values($excluded ?? []) as $i => $id) {
            $id = (int) $id;
            if ($id <= 0 || ! ModifierGroup::query()->whereKey($id)->exists()) {
                $errors["excluded_group_ids.{$i}"] = __('products.modifier_group_unknown');
            } elseif (isset($seenExcluded[$id])) {
                $errors["excluded_group_ids.{$i}"] = __('products.modifier_group_duplicate');
            }
            $seenExcluded[$id] = true;
        }

        foreach (array_values($links) as $i => $l) {
            $gid = (int) ($l['group_id'] ?? 0);
            $min = (int) ($l['min_select'] ?? 0);
            $max = array_key_exists('max_select', $l) && $l['max_select'] !== null ? (int) $l['max_select'] : null;

            if ($gid <= 0 || ! ModifierGroup::query()->whereKey($gid)->exists()) {
                $errors["links.{$i}.group_id"] = __('products.modifier_group_unknown');
            } elseif (isset($seen[$gid])) {
                $errors["links.{$i}.group_id"] = __('products.modifier_group_duplicate');
            }
            $seen[$gid] = true;

            if ($min < 0) {
                $errors["links.{$i}.min_select"] = __('products.modifier_min_invalid');
            }
            if ($max !== null && ($max < 1 || $max < $min)) {
                $errors["links.{$i}.max_select"] = __('products.modifier_max_invalid');
            }

            $clean[] = [
                'group_id' => $gid, 'is_required' => (bool) ($l['is_required'] ?? false), 'min_select' => $min,
                'max_select' => $max, 'display_order' => (int) ($l['display_order'] ?? $i + 1),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }
}
