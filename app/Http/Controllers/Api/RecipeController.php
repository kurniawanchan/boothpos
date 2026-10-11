<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BomRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRecipeRequest;
use App\Models\Product;
use App\Models\Recipe;
use App\Services\RecipeService;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 043 — resep berversi. Baca = menu `products`; tulis = menu `products` + `menu.recipe.edit`. Logika di RecipeService. */
class RecipeController extends Controller
{
    public function __construct(private RecipeService $recipes) {}

    public function index(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->canAccessMenu('products'), 403, __('reports.not_authorized_generic'));
        $this->recipes->applyDue($request->user()->id);

        return response()->json(['data' => $this->recipes->versions($product, $this->recipes->mayCost($request->user()))]);
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

    public function recalculate(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->canAccessMenu('products') && $request->user()->hasPermission(PermissionCatalog::RECIPE_EDIT), 403, __('reports.not_authorized_generic'));

        return response()->json($this->recipes->recalculate($product, $request->user()));
    }
}
