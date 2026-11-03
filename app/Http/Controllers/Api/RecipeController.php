<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BomRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRecipeRequest;
use App\Http\Requests\UpdateRecipeRequest;
use App\Models\Product;
use App\Models\Recipe;
use App\Services\RecipeService;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 043/089 — resep (tanpa versi sejak 089). Baca = menu `products`; tulis = menu `products` + `menu.recipe.edit`. Logika di RecipeService. */
class RecipeController extends Controller
{
    public function __construct(private RecipeService $recipes) {}

    public function index(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->canAccessMenu('products'), 403, __('reports.not_authorized_generic'));
        $this->recipes->applyDue($request->user()->id);

        return response()->json(['data' => $this->recipes->list($product, $this->recipes->mayCost($request->user()))]);
    }

    /**
     * 2026-11-02 — pilihan modifier yang boleh dipilih sebuah tabel bahan: grup EFEKTIF item (tautan + warisan kategori,
     * minus yang dilepas), hanya pilihan aktif. Aturannya satu: ModifierResolver::effectiveGroups.
     */
    public function modifierOptions(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->canAccessMenu('products'), 403, __('reports.not_authorized_generic'));

        $groups = app(\App\Services\ModifierResolver::class)->effectiveGroups($product)->map(fn ($g) => [
            'id' => $g->id, 'name' => $g->name,
            'options' => $g->options->map(fn ($o) => ['id' => $o->id, 'name' => $o->name])->values()->all(),
        ])->values()->all();

        return response()->json(['data' => $groups]);
    }

    public function show(Request $request, Recipe $recipe): JsonResponse
    {
        abort_unless($request->user()->canAccessMenu('products'), 403, __('reports.not_authorized_generic'));

        return response()->json($this->recipes->present($recipe, $this->recipes->mayCost($request->user())));
    }

    public function store(StoreRecipeRequest $request, Product $product): JsonResponse
    {
        try {
            $recipe = $this->recipes->create($product, $request->validated(), $request->user());
        } catch (BomRuleException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason] + $e->context, $e->status);
        }

        return response()->json($this->recipes->present($recipe, $this->recipes->mayCost($request->user())), 201);
    }

    /** 089 — ubah resep di tempat. 409 `recipe_archived` untuk resep arsip; 409 `cost_source_conflict` bila varian ber-BOM lengkap. */
    public function update(UpdateRecipeRequest $request, Recipe $recipe): JsonResponse
    {
        try {
            $updated = $this->recipes->update($recipe, $request->validated(), $request->user());
        } catch (BomRuleException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason] + $e->context, $e->status);
        }

        return response()->json($this->recipes->present($updated, $this->recipes->mayCost($request->user())));
    }

    /** 089 — hapus SATU resep. */
    public function destroyOne(Request $request, Recipe $recipe): JsonResponse
    {
        abort_unless($request->user()->canAccessMenu('products') && $request->user()->hasPermission(PermissionCatalog::RECIPE_EDIT), 403, __('reports.not_authorized_generic'));

        $this->recipes->removeOne($recipe, $request->user());

        return response()->json(['message' => __('products.recipe_removed'), 'removed' => 1]);
    }

    /** 2026-10-31 — hapus SEMUA resep sebuah item. 404 bila item tidak punya resep. Izin sama dengan "Hitung ulang". */
    public function destroy(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->canAccessMenu('products') && $request->user()->hasPermission(PermissionCatalog::RECIPE_EDIT), 403, __('reports.not_authorized_generic'));

        $removed = $this->recipes->remove($product, $request->user());
        abort_if($removed === 0, 404, __('products.recipe_none'));

        return response()->json(['message' => __('products.recipe_removed'), 'removed' => $removed, 'removed_versions' => $removed]);
    }

    public function recalculate(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->canAccessMenu('products') && $request->user()->hasPermission(PermissionCatalog::RECIPE_EDIT), 403, __('reports.not_authorized_generic'));

        return response()->json($this->recipes->recalculate($product, $request->user()));
    }
}
