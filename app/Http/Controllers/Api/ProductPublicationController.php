<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublishProductRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductPublicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer commerce (CONTRACT §6) — kendali terbit STAF untuk etalase publik:
 * POST /products/{product}/publish|unpublish dan
 * POST /variants/{variant}/publish|unpublish.
 *
 * Controller tersendiri (bukan method baru di ProductController) supaya
 * permukaan "membuka barang ke publik" mudah ditinjau. Lapis izinnya:
 *  1. rute: `ability:products,publish` (aksi bernama, terpisah dari Ubah);
 *  2. di sini: policy `update` produk induknya (punya menu Produk);
 *  3. route-model binding lewat SellerScope dan DataModeScope: produk seller
 *     lain atau mode data lain menjawab 404.
 * Semua perubahan dan log-nya ada di ProductPublicationService.
 */
class ProductPublicationController extends Controller
{
    public function __construct(private ProductPublicationService $publication) {}

    public function publishProduct(PublishProductRequest $request, Product $product): JsonResponse
    {
        $this->publication->publishProduct($product, $request->boolean('with_variants'), $request->user());

        return $this->productResponse($product);
    }

    public function unpublishProduct(Request $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $this->publication->unpublishProduct($product, $request->user());

        return $this->productResponse($product);
    }

    public function publishVariant(Request $request, ProductVariant $variant): JsonResponse
    {
        // ProductVariant tidak punya policy sendiri: diotorisasi lewat produk induknya (pola UpdateVariantRequest).
        $this->authorize('update', $variant->product);

        return response()->json(new ProductVariantResource($this->publication->publishVariant($variant, $request->user())->fresh()));
    }

    public function unpublishVariant(Request $request, ProductVariant $variant): JsonResponse
    {
        $this->authorize('update', $variant->product);

        return response()->json(new ProductVariantResource($this->publication->unpublishVariant($variant, $request->user())->fresh()));
    }

    /** Bentuk yang sama dengan unggah gambar produk: produk + seller + kategori + variannya. */
    private function productResponse(Product $product): JsonResponse
    {
        return response()->json(new ProductResource($product->fresh(['artist', 'category', 'variants'])));
    }
}
