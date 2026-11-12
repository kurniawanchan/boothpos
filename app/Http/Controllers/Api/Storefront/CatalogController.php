<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Exceptions\CustomerCommerceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\ListProductsRequest;
use App\Http\Resources\Storefront\StorefrontCategoryResource;
use App\Http\Resources\Storefront\StorefrontProductCardResource;
use App\Http\Resources\Storefront\StorefrontProductDetailResource;
use App\Services\CustomerCommerce\StorefrontCatalogService;
use Illuminate\Http\JsonResponse;

/**
 * Customer commerce (CONTRACT §6) — katalog etalase PUBLIK untuk aplikasi
 * Android: kategori, daftar produk, detail produk.
 *
 * Tanpa autentikasi; token apa pun yang dikirim diabaikan (rute ini tidak
 * memasang guard). Hanya membaca. Controller ini sengaja tidak berbagi apa
 * pun dengan ProductController / ProductCatalogController staf: aturan
 * kelayakan tampil ada di scope model, bentuk responsnya di resource
 * Storefront*, dan query-nya di StorefrontCatalogService.
 */
class CatalogController extends Controller
{
    public function __construct(private StorefrontCatalogService $catalog) {}

    public function categories(): JsonResponse
    {
        return response()->json(['data' => StorefrontCategoryResource::collection($this->catalog->categories())]);
    }

    public function products(ListProductsRequest $request): JsonResponse
    {
        $products = $this->catalog->paginate($request->validated());

        return response()->json([
            'data' => StorefrontProductCardResource::collection($products->items()),
            'meta' => [
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'last_page' => $products->lastPage(),
            ],
        ]);
    }

    /**
     * Slug dicari eksplisit lewat scope kelayakan tampil, bukan route-model
     * binding: produk yang ada tetapi tersembunyi harus menjawab 404 yang
     * SAMA dengan slug yang tidak pernah ada.
     */
    public function product(string $slug): JsonResponse
    {
        $product = $this->catalog->findBySlug($slug);

        if ($product === null) {
            throw CustomerCommerceException::make('not_found', 404);
        }

        return response()->json(['data' => new StorefrontProductDetailResource($product)]);
    }
}
