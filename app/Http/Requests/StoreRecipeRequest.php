<?php

namespace App\Http\Requests;

use App\Support\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;

/** 043 — POST /products/{id}/recipes (089: satu resep, tanpa versi; juga dipakai PUT /recipes/{id} lewat UpdateRecipeRequest). Menu produk + izin `menu.recipe.edit`. Aturan isi dicek RecipeService. */
class StoreRecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $u = $this->user();

        return $u !== null && $u->canAccessMenu('products') && $u->hasPermission(PermissionCatalog::RECIPE_EDIT);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            // Batas = lebar kolom (decimal(10,3) dan decimal(12,4)): nilai di atasnya jadi 422, bukan galat DB 500.
            'yield' => ['required', 'numeric', 'max:9999999.999'],
            'effective_from' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
            // Bentuk baru: `blocks` = tabel bahan per (varian, pilihan modifier opsional). Bentuk lama (`variant_ids` + `lines`)
            // tetap diterima sebagai satu blok dasar per varian.
            'blocks' => ['sometimes', 'array', 'max:60'],
            'blocks.*.variant_id' => ['required', 'integer'],
            'blocks.*.modifier_option_id' => ['nullable', 'integer'],
            'blocks.*.lines' => ['present', 'array', 'max:60'],
            'blocks.*.lines.*.material_id' => ['required', 'integer'],
            'blocks.*.lines.*.quantity' => ['required', 'numeric', 'max:99999999.9999'],
            'blocks.*.lines.*.shrinkage_pct' => ['sometimes', 'nullable', 'numeric'],
            'variant_ids' => ['exclude_with:blocks', 'present', 'array'],
            'variant_ids.*' => ['integer'],
            'lines' => ['exclude_with:blocks', 'present', 'array', 'max:60'],
            'lines.*.material_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'max:99999999.9999'],
            'lines.*.shrinkage_pct' => ['sometimes', 'nullable', 'numeric'],
        ];
    }
}
