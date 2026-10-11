<?php

namespace App\Http\Requests;

use App\Support\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;

/** 043 — POST /products/{id}/recipes: menu produk + izin `menu.recipe.edit`. Aturan isi dicek RecipeService. */
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
            'yield' => ['required', 'numeric'],
            'effective_from' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
            'variant_ids' => ['present', 'array'],
            'variant_ids.*' => ['integer'],
            'lines' => ['present', 'array', 'max:60'],
            'lines.*.material_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric'],
            'lines.*.shrinkage_pct' => ['sometimes', 'nullable', 'numeric'],
        ];
    }
}
