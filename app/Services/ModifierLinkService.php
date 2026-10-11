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
     * @return array{applied: bool, change: MenuChange|null}
     *
     * @throws ValidationException
     */
    public function replace(Product $product, array $links, User $by): array
    {
        $clean = $this->validate($links);

        return DB::transaction(function () use ($product, $clean, $by) {
            if (MenuSettings::releaseEnabled()) {
                $change = MenuChange::create([
                    'kind' => MenuChange::KIND_MODIFIER_LINK, 'subject_type' => 'product', 'subject_id' => $product->id,
                    'payload' => ['product_name' => $product->name, 'links' => $clean],
                    'status' => MenuChange::PENDING, 'requested_by' => $by->id,
                ]);
                $this->log->log(userId: $by->id, action: 'menu_change_requested', entityType: 'Product', entityId: $product->id, description: "Tautan modifier {$product->name} diajukan ke rilis.", newValues: ['change_id' => $change->id]);

                return ['applied' => false, 'change' => $change];
            }

            $this->applyLinks($product, $clean, $by);

            return ['applied' => true, 'change' => null];
        });
    }

    /** Penerapan langsung (juga dipanggil ModifierLinkApplier). */
    public function applyLinks(Product $product, array $links, User $by): void
    {
        $before = $product->modifierGroups()->get()->map(fn ($g) => ['group_id' => $g->id, 'is_required' => (bool) $g->pivot->is_required, 'min_select' => $g->pivot->min_select, 'max_select' => $g->pivot->max_select])->all();

        $product->modifierGroups()->sync(collect($links)->mapWithKeys(fn ($l) => [$l['group_id'] => [
            'has_rules' => true, 'is_required' => $l['is_required'], 'min_select' => $l['min_select'],
            'max_select' => $l['max_select'], 'display_order' => $l['display_order'],
        ]])->all());

        $this->log->log(userId: $by->id, action: 'modifier_links_updated', entityType: 'Product', entityId: $product->id, description: "Tautan modifier {$product->name} diubah.", oldValues: ['links' => $before], newValues: ['links' => $links]);
    }

    /** @return list<array{group_id: int, is_required: bool, min_select: int, max_select: int|null, display_order: int}> */
    private function validate(array $links): array
    {
        $errors = [];
        $clean = [];
        $seen = [];

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
