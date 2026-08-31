<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\StoreVariantRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Requests\UpdateVariantRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ProductVariantResource;
use App\Models\Artist;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductCodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function __construct(private ProductCodeGenerator $codeGenerator) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $products = Product::query()
            ->with(['artist', 'category'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->string('search');
                $q->where(function ($q2) use ($term) {
                    $q2->where('name', 'like', "%{$term}%")
                       ->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', "%{$term}%"));
                });
            })
            ->when($request->filled('artist_id'), fn ($q) => $q->where('artist_id', $request->integer('artist_id')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->has('is_preorder'), fn ($q) => $q->where('is_preorder', $request->boolean('is_preorder')))
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => ProductResource::collection($products->items()),
            'meta' => [
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'last_page' => $products->lastPage(),
            ],
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $data = $request->validated();

        $artist = Artist::findOrFail($data['artist_id']);
        $category = Category::findOrFail($data['category_id']);

        $segment = $data['product_segment'] ?? $this->codeGenerator->deriveSegmentFromName($data['name']);
        $codePrefix = $this->codeGenerator->buildCodePrefix($artist->code, $category->code, $segment);

        $product = DB::transaction(function () use ($data, $segment, $codePrefix) {
            $product = Product::create([
                'artist_id' => $data['artist_id'],
                'category_id' => $data['category_id'],
                'code_prefix' => $codePrefix,
                'product_segment' => strtoupper($segment),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_preorder' => $data['is_preorder'] ?? false,
                'preorder_eta' => $data['preorder_eta'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            foreach ($data['variants'] as $variantInput) {
                $sku = $this->codeGenerator->nextVariantSku($product);
                $product->variants()->create([
                    'sku' => $sku,
                    'variant_name' => $variantInput['variant_name'],
                    'cost_price' => $variantInput['cost_price'] ?? 0,
                    'sell_price' => $variantInput['sell_price'],
                    'low_stock_alert' => $variantInput['low_stock_alert'] ?? null,
                ]);
            }

            return $product;
        });

        return response()->json(new ProductResource($product->load(['artist', 'category', 'variants'])), 201);
    }

    public function show(Product $product): JsonResponse
    {
        $this->authorize('view', $product);

        return response()->json(new ProductResource($product->load(['artist', 'category', 'variants'])));
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $product->update($request->validated());

        return response()->json(new ProductResource($product->fresh(['artist', 'category', 'variants'])));
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        $hasActiveVariants = $product->variants()->where('is_active', true)->exists();

        if ($hasActiveVariants) {
            return response()->json([
                'message' => 'Produk masih memiliki varian aktif. Nonaktifkan seluruh varian terlebih dahulu.',
            ], 409);
        }

        $product->delete();

        return response()->json(null, 204);
    }

    public function storeVariant(StoreVariantRequest $request, Product $product): JsonResponse
    {
        $sku = $this->codeGenerator->nextVariantSku($product);

        $variant = $product->variants()->create([
            'sku' => $sku,
            'variant_name' => $request->validated('variant_name'),
            'cost_price' => $request->validated('cost_price') ?? 0,
            'sell_price' => $request->validated('sell_price'),
            'low_stock_alert' => $request->validated('low_stock_alert'),
        ]);

        return response()->json(new ProductVariantResource($variant), 201);
    }

    public function updateVariant(UpdateVariantRequest $request, ProductVariant $variant): JsonResponse
    {
        $variant->update($request->validated());

        return response()->json(new ProductVariantResource($variant->fresh()));
    }

    public function lookupVariants(Request $request): JsonResponse
    {
        $term = (string) $request->query('q', '');
        $limit = min((int) $request->integer('limit', 20), 50);

        if ($term === '') {
            return response()->json(['data' => []]);
        }

        $variants = ProductVariant::query()
            ->with(['product.artist'])
            ->where('is_active', true)
            ->where(function ($q) use ($term) {
                $q->where('sku', 'like', "%{$term}%")
                  ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%"));
            })
            ->limit($limit)
            ->get();

        $data = $variants->map(fn (ProductVariant $v) => [
            'variant_id' => $v->id,
            'sku' => $v->sku,
            'label' => $v->product->name.' — '.$v->variant_name,
            'artist_name' => $v->product->artist->name,
            'sell_price' => number_format((float) $v->sell_price, 2, '.', ''),
            'current_stock' => $v->current_stock,
            'is_preorder' => (bool) $v->product->is_preorder,
        ]);

        return response()->json(['data' => $data]);
    }
}
