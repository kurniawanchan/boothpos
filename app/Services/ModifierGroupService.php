<?php

namespace App\Services;

use App\Exceptions\InputRejectedException;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 028 US3 — pembuatan/ubah/lampir/hapus grup modifier. Aturan turunan
 * dinormalisasi DI SINI (bukan dipercaya dari klien):
 *   tunggal  → max selalu 1; min = 1 bila wajib, 0 bila opsional;
 *   jamak    → bila wajib, min minimal 1; max boleh kosong (tanpa batas atas).
 * Hanya kontradiksi nyata (min > max) yang ditolak.
 */
class ModifierGroupService
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): ModifierGroup
    {
        return DB::transaction(function () use ($data) {
            $group = ModifierGroup::create($this->normalised($data, null));
            $this->syncOptions($group, $data['options'] ?? []);

            return $this->loaded($group);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(ModifierGroup $group, array $data): ModifierGroup
    {
        return DB::transaction(function () use ($group, $data) {
            $group->update($this->normalised($data, $group));

            // `options` hadir → penggantian PENUH menurut id; tidak hadir → opsi tak disentuh.
            if (array_key_exists('options', $data)) {
                $this->syncOptions($group, $data['options']);
            }

            return $this->loaded($group);
        });
    }

    /**
     * @param  array<int, int>  $productIds
     * @param  array<int, int>  $categoryIds
     *
     * @throws InputRejectedException
     */
    public function setAttachments(ModifierGroup $group, array $productIds, array $categoryIds): ModifierGroup
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));

        // Lewat Eloquent (patuh DataModeScope): id dari mode lain dianggap tidak ada.
        $this->assertAllExist(Product::class, $productIds, 'product_ids');
        $this->assertAllExist(Category::class, $categoryIds, 'category_ids');

        if (($productIds || $categoryIds) && ! $group->options()->where('is_active', true)->exists()) {
            throw InputRejectedException::withMessages(['modifier_group' => [__('restaurant.modifier_group_has_no_options')]]);
        }

        return DB::transaction(function () use ($group, $productIds, $categoryIds) {
            $group->products()->sync($productIds);
            $group->categories()->sync($categoryIds);

            return $this->loaded($group);
        });
    }

    /** @throws ValidationException 409 bila grup pernah muncul di baris order */
    public function delete(ModifierGroup $group, User $user): void
    {
        // Snapshot baris order menyimpan group_id → grup yang pernah dipakai tidak boleh
        // hilang (struk/laporan lama masih merujuknya). Nonaktifkan saja.
        $used = DB::table('order_items')->whereJsonContains('modifiers', [['group_id' => $group->id]])->exists();
        if ($used) {
            throw ValidationException::withMessages(['modifier_group' => __('restaurant.modifier_group_in_use')]);
        }

        DB::transaction(function () use ($group, $user) {
            $snapshot = $group->only($group->getFillable());

            $group->products()->detach();
            $group->categories()->detach();
            $group->options()->delete();
            $group->delete();

            $this->activityLogger->log(
                userId: $user->id,
                action: 'deleted',
                entityType: 'ModifierGroup',
                entityId: $group->id,
                description: "Menghapus grup modifier {$group->name}.",
                oldValues: $snapshot,
            );
        });
    }

    public function loaded(ModifierGroup $group): ModifierGroup
    {
        return $group->fresh(['options', 'products:id', 'categories:id']);
    }

    /** @param array<string, mixed> $data */
    private function normalised(array $data, ?ModifierGroup $existing): array
    {
        $type = $data['selection_type'] ?? $existing?->selection_type ?? ModifierGroup::SINGLE;
        $required = (bool) ($data['is_required'] ?? $existing?->is_required ?? false);
        $min = (int) ($data['min_select'] ?? $existing?->min_select ?? 0);
        $max = array_key_exists('max_select', $data) ? $data['max_select'] : $existing?->max_select;

        if ($type === ModifierGroup::SINGLE) {
            $min = $required ? 1 : 0;
            $max = 1;
        } else {
            $min = $required ? max(1, $min) : $min;
            if ($max !== null && $min > (int) $max) {
                throw InputRejectedException::withMessages(['min_select' => [__('restaurant.modifier_min_above_max')]]);
            }
        }

        $out = ['selection_type' => $type, 'is_required' => $required, 'min_select' => $min, 'max_select' => $max === null ? null : (int) $max];
        foreach (['name', 'display_order', 'is_active'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key];
            }
        }

        return $out;
    }

    /**
     * Penggantian opsi menurut id: ada id → ubah; tanpa id → buat; yang TIDAK dikirim
     * dinonaktifkan (tidak pernah dihapus — baris lama masih merujuk namanya lewat snapshot).
     *
     * @param array<int, array<string, mixed>> $options
     */
    private function syncOptions(ModifierGroup $group, array $options): void
    {
        $existing = $group->options()->get()->keyBy('id');
        $kept = [];

        foreach (array_values($options) as $i => $row) {
            $attrs = [
                'name' => $row['name'],
                'price_delta' => $row['price_delta'],
                'display_order' => $row['display_order'] ?? $i,
                'is_active' => $row['is_active'] ?? true,
            ];

            if (! empty($row['id'])) {
                $option = $existing->get((int) $row['id']);
                if (! $option) {
                    throw InputRejectedException::withMessages(['options' => [__('restaurant.modifier_option_not_in_group')]]);
                }
                $option->update($attrs);
                $kept[] = $option->id;
            } else {
                $kept[] = $group->options()->create($attrs)->id;
            }
        }

        $group->options()->whereNotIn('id', $kept)->update(['is_active' => false]);
    }

    /** @param class-string<\Illuminate\Database\Eloquent\Model> $model */
    private function assertAllExist(string $model, array $ids, string $field): void
    {
        if ($ids && $model::whereIn('id', $ids)->count() !== count($ids)) {
            throw InputRejectedException::withMessages([$field => [__('restaurant.attachment_not_found')]]);
        }
    }
}
