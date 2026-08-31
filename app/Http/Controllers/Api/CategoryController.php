<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CategoryController extends Controller
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Category::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $categories = Category::query()
            ->withCount('products')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('display_order')
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => CategoryResource::collection($categories->items()),
            'meta' => [
                'current_page' => $categories->currentPage(),
                'per_page' => $categories->perPage(),
                'total' => $categories->total(),
                'last_page' => $categories->lastPage(),
            ],
        ]);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = Category::create($request->validated());

        return response()->json(new CategoryResource($category), 201);
    }

    public function show(Category $category): JsonResponse
    {
        $this->authorize('view', $category);

        $category->loadCount('products');

        return response()->json(new CategoryResource($category));
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $category->update($request->validated());

        return response()->json(new CategoryResource($category->fresh()));
    }

    public function destroy(Request $request, Category $category): JsonResponse
    {
        $this->authorize('delete', $category);

        // Guard 1 — bisa dan HARUS ditegakkan sekarang: FK RESTRICT di
        // skema tidak berlaku untuk soft delete, jadi pemeriksaan manual
        // ini bukan opsional.
        if ($category->children()->where('is_active', true)->exists()) {
            return response()->json([
                'message' => 'Kategori masih memiliki sub-kategori aktif dan tidak dapat dihapus.',
            ], 409);
        }

        // Guard 2 — sama seperti Artist, ditunda sampai modul Product ada.
        if (Schema::hasTable('products')) {
            $hasActiveProducts = DB::table('products')
                ->where('category_id', $category->id)
                ->where('is_active', true)
                ->exists();

            if ($hasActiveProducts) {
                return response()->json([
                    'message' => 'Kategori masih memiliki produk aktif dan tidak dapat dihapus.',
                ], 409);
            }
        }

        // F13.4 — hapus data adalah tindakan sensitif.
        DB::transaction(function () use ($category, $request) {
            $snapshot = $category->only($category->getFillable());

            $category->delete();

            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'deleted',
                entityType: 'Category',
                entityId: $category->id,
                description: "Menghapus kategori {$category->code} ({$category->name}).",
                oldValues: $snapshot,
            );
        });

        return response()->json(null, 204);
    }
}
