<?php

namespace App\Services;

use App\Exceptions\InputRejectedException;
use App\Models\Category;
use App\Models\Material;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
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
    public function create(array $data, ?int $userId = null): ModifierGroup
    {
        return DB::transaction(function () use ($data, $userId) {
            $group = ModifierGroup::create($this->normalised($data, null));
            $this->logMaterialChanges($group, $this->syncOptions($group, $data['options'] ?? []), $userId);

            return $this->loaded($group);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(ModifierGroup $group, array $data, ?int $userId = null): ModifierGroup
    {
        return DB::transaction(function () use ($group, $data, $userId) {
            $group->update($this->normalised($data, $group));

            // `options` hadir → penggantian PENUH menurut id; tidak hadir → opsi tak disentuh.
            if (array_key_exists('options', $data)) {
                $this->logMaterialChanges($group, $this->syncOptions($group, $data['options']), $userId);
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
        return $group->fresh(['options.materials.material', 'products:id', 'categories:id']);
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
        // 2026-10-31 — `portion_per_option`: tanpa kunci = tidak berubah (klien lama aman).
        foreach (['name', 'display_order', 'is_active', 'portion_per_option'] as $key) {
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
     * @return array<int, array{option: string, old: array<int, array{material_id:int, quantity:string}>, new: array<int, array{material_id:int, quantity:string}>}>
     *         perubahan pemetaan bahan per id pilihan (087), kosong bila tidak ada yang berubah
     */
    private function syncOptions(ModifierGroup $group, array $options): array
    {
        $materialChanges = [];
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
            } else {
                $option = $group->options()->create($attrs);
            }
            $kept[] = $option->id;

            if (array_key_exists('materials', $row)) {
                $change = $this->syncOptionMaterials($option, (array) $row['materials'], $i);
                if ($change !== null) {
                    $materialChanges[$option->id] = ['option' => $option->name] + $change;
                }
            }
        }

        // Pemetaan bahan pilihan yang dinonaktifkan dibiarkan: pilihan nonaktif tidak bisa dipesan, jadi tak memotong.
        $group->options()->whereNotIn('id', $kept)->update(['is_active' => false]);

        return $materialChanges;
    }

    /**
     * 087 — ganti penuh bahan sebuah pilihan. Bahan diperiksa lewat model (global scope mode DEMO/LIVE dan
     * soft delete), jadi bahan mode lain atau yang terhapus tidak ditemukan; yang nonaktif ditolak.
     *
     * @param  array<int, array{material_id:int, quantity:numeric}>  $rows
     * @return array{old: array<int, array{material_id:int, quantity:string}>, new: array<int, array{material_id:int, quantity:string}>}|null
     */
    private function syncOptionMaterials(ModifierOption $option, array $rows, int $optionIndex): ?array
    {
        $rows = array_values($rows);
        $found = Material::query()->whereIn('id', array_map(fn ($r) => (int) $r['material_id'], $rows))->get()->keyBy('id');

        foreach ($rows as $j => $r) {
            $material = $found->get((int) $r['material_id']);
            if ($material === null || ! $material->is_active) {
                throw InputRejectedException::withMessages([
                    "options.{$optionIndex}.materials.{$j}.material_id" => [$material === null
                        ? __('vendors_materials.option_material_not_found')
                        : __('vendors_materials.option_material_inactive', ['material' => $material->name])],
                ]);
            }
        }

        $shape = fn ($list) => collect($list)
            ->map(fn ($r) => ['material_id' => (int) $r['material_id'], 'quantity' => number_format((float) $r['quantity'], 4, '.', '')])
            ->sortBy('material_id')->values()->all();

        $old = $shape($option->materials()->get(['material_id', 'quantity'])->toArray());
        $new = $shape($rows);
        if ($old === $new) {
            return null;
        }

        $option->materials()->delete();
        foreach ($new as $r) {
            $option->materials()->create($r);
        }

        return ['old' => $old, 'new' => $new];
    }

    /** 087 FR-006 — satu entri log per penyimpanan grup yang mengubah pemetaan bahan, di transaksi yang sama. */
    private function logMaterialChanges(ModifierGroup $group, array $changes, ?int $userId): void
    {
        if ($changes === []) {
            return;
        }

        $this->activityLogger->log(
            userId: $userId,
            action: 'modifier_materials_changed',
            entityType: 'ModifierGroup',
            entityId: $group->id,
            description: "Mengubah bahan pilihan modifier di grup {$group->name}: ".implode(', ', array_column($changes, 'option')).'.',
            oldValues: array_map(fn ($c) => $c['old'], $changes),
            newValues: array_map(fn ($c) => $c['new'], $changes),
        );
    }

    /** @param class-string<\Illuminate\Database\Eloquent\Model> $model */
    private function assertAllExist(string $model, array $ids, string $field): void
    {
        if ($ids && $model::whereIn('id', $ids)->count() !== count($ids)) {
            throw InputRejectedException::withMessages([$field => [__('restaurant.attachment_not_found')]]);
        }
    }
}
