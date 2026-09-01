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
use App\Services\ActivityLogger;
use App\Services\ImageUploadService;
use App\Services\ProductCodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function __construct(
        private ProductCodeGenerator $codeGenerator,
        private ActivityLogger $activityLogger,
        private ImageUploadService $imageUploadService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        // ?with_variants=1 — OPT-IN, bukan selalu aktif. Dua alasan:
        // (1) layar Kelola Produk hanya menampilkan nama/artist/kategori,
        //     jadi memuat varian untuk semua konsumen memperbesar payload
        //     tanpa dipakai;
        // (2) default response tidak berubah sama sekali, jadi ini
        //     penambahan aditif — tidak ada konsumen lama yang perlu
        //     menyesuaikan diri.
        // Dimuat lewat eager-load relasi (bukan lazy-load di dalam
        // ProductResource) supaya tetap 2 query, bukan N+1.
        // Varian TIDAK difilter is_active di sini, persis seperti
        // GET /products/{id} — supaya kedua endpoint memberi isi yang sama
        // untuk produk yang sama, bukan diam-diam berbeda.
        $withVariants = $request->boolean('with_variants');

        $products = Product::query()
            ->with(array_merge(['artist', 'category'], $withVariants ? ['variants'] : []))
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
        // F13.4 — "ubah harga" mencakup update produk/varian secara umum;
        // snapshot before/after ditulis apa adanya (bukan hanya kalau harga
        // ikut berubah) supaya activity_logs tetap satu sumber audit yang
        // konsisten untuk seluruh perubahan master data produk.
        DB::transaction(function () use ($request, $product) {
            $before = $product->only($product->getFillable());

            $product->update($request->validated());

            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'updated',
                entityType: 'Product',
                entityId: $product->id,
                description: "Mengubah produk {$product->name}.",
                oldValues: $before,
                newValues: $product->only($product->getFillable()),
            );
        });

        return response()->json(new ProductResource($product->fresh(['artist', 'category', 'variants'])));
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        $hasActiveVariants = $product->variants()->where('is_active', true)->exists();

        if ($hasActiveVariants) {
            return response()->json([
                'message' => 'Produk masih memiliki varian aktif. Nonaktifkan seluruh varian terlebih dahulu.',
            ], 409);
        }

        // F13.4 — hapus data adalah tindakan sensitif.
        DB::transaction(function () use ($product, $request) {
            $snapshot = $product->only($product->getFillable());

            $product->delete();

            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'deleted',
                entityType: 'Product',
                entityId: $product->id,
                description: "Menghapus produk {$product->name} ({$product->code_prefix}).",
                oldValues: $snapshot,
            );
        });

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
        // F13.4 — "ubah harga" (price_changed): satu-satunya endpoint yang
        // menyentuh cost_price/sell_price. Snapshot ditulis apa adanya
        // (bukan hanya diff kolom harga) supaya operator bisa melihat
        // konteks lengkap perubahan, bukan cuma angka harga saja.
        DB::transaction(function () use ($request, $variant) {
            $before = $variant->only($variant->getFillable());

            $variant->update($request->validated());

            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'price_changed',
                entityType: 'ProductVariant',
                entityId: $variant->id,
                description: "Mengubah varian {$variant->sku}.",
                oldValues: $before,
                newValues: $variant->only($variant->getFillable()),
            );
        });

        return response()->json(new ProductVariantResource($variant->fresh()));
    }

    /**
     * Task 5 — dipisah dari store()/update() alih-alih memperluas
     * keduanya menerima multipart, karena store() sudah menerima array
     * bersarang 'variants' yang rumit dikirim via multipart/form-data.
     * Endpoint gambar terpisah lebih sederhana untuk frontend (satu
     * <input type=file> yang bisa dipicu kapan pun, termasuk saat
     * mengedit produk yang sudah ada) dan konsisten dengan pola upload
     * lain di kodebase ini (PaymentProofController::store()).
     */
    public function uploadImage(Request $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $validated = $request->validate([
            'image' => [
                'required',
                'file',
                Rule::file()->max(ImageUploadService::MAX_KILOBYTES)->rules(['mimes:jpeg,png']),
            ],
        ]);

        $oldPath = $product->image_path;

        $product->image_path = $this->imageUploadService->store($validated['image'], 'products');
        $product->save();

        $this->imageUploadService->delete($oldPath);

        return response()->json(new ProductResource($product->fresh(['artist', 'category', 'variants'])));
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
