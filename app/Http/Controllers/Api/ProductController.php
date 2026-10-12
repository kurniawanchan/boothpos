<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\StoreVariantRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Requests\UpdateVariantRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ProductVariantResource;
use App\Models\Outlet;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ActivityLogger;
use App\Services\ImageUploadService;
use App\Services\ProductCodeGenerator;
use App\Services\VariantBomService;
use App\Support\ReportSplit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function __construct(
        private ProductCodeGenerator $codeGenerator,
        private ActivityLogger $activityLogger,
        private ImageUploadService $imageUploadService,
        private VariantBomService $bomService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        // 043 — harga/rilis berjadwal yang jatuh tempo diterapkan di titik masuk ini (daftar POS dan daftar Produk).
        app(\App\Services\ReleaseService::class)->publishDueCached();
        app(\App\Services\RecipeService::class)->applyDueCached();

        // 043 — MODE KATALOG (layar Produk baru): aktif bila `tab` dikirim. Tanpa `tab`, perilaku lama
        // (POS, pre-order, dst.) tetap persis seperti sebelumnya.
        if ($request->has('tab')) {
            return $this->catalog(app(\App\Http\Requests\ProductCatalogRequest::class));
        }

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
        // 028 — ?with_modifiers=1 juga OPT-IN (daftar tetap ramping): grup efektif =
        // grup produk ∪ grup kategorinya, dimuat lewat eager-load supaya tetap konstan.
        $withModifiers = $request->boolean('with_modifiers');

        // 045 Stage 4 — `pos=1` (layar POS/tab): menu menurut outlet SHIFT pengguna. Item yang tidak dibawa outlet itu tidak
        // ditawarkan, dan harga khusus outlet itu ikut dikirim (`outlet_price`) supaya kartu = yang akan ditagihkan.
        $orderingOutlet = $request->boolean('pos') ? app(\App\Support\OutletContext::class)->orderingId() : null;
        app(\App\Support\OutletPriceBook::class)->useOrderingOutlet($orderingOutlet);
        $notCarriedHere = $orderingOutlet === null ? [] : \App\Models\OutletItemSetting::query()
            ->where('outlet_id', $orderingOutlet)->where('is_carried', false)->pluck('product_id')->all();

        $products = Product::query()
            ->with(array_merge(
                ['homeOutlet', 'category'],
                $withVariants ? ['variants'] : [],
                $withModifiers ? ['modifierGroups.options', 'category.modifierGroups.options'] : [],
            ))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->string('search');
                $q->where(function ($q2) use ($term) {
                    $q2->where('name', 'like', "%{$term}%")
                       ->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', "%{$term}%")
                           ->orWhere('variant_name', 'like', "%{$term}%"));
                });
            })
            // 005-ux-enhancements-dashboard (US1) — home_outlet_id/category_id
            // sekarang array (multi-select dropdown di Products/POS),
            // bukan lagi skalar tunggal. $request->array() menormalkan
            // baik `home_outlet_id[]=1&home_outlet_id[]=2` maupun `home_outlet_id=1` lama
            // menjadi array, jadi kompatibel mundur tanpa perubahan pada
            // pemanggil lain. array_filter membuang string kosong supaya
            // whereIn([]) (yang selalu false) tidak diam-diam mengosongkan
            // hasil ketika parameter ada tapi bernilai kosong.
            ->when(count(array_filter($request->array('home_outlet_id'))) > 0, fn ($q) => $q->whereIn('home_outlet_id', array_filter($request->array('home_outlet_id'))))
            ->when(count(array_filter($request->array('category_id'))) > 0, fn ($q) => $q->whereIn('category_id', array_filter($request->array('category_id'))))
            ->when($request->has('is_preorder'), fn ($q) => $q->where('is_preorder', $request->boolean('is_preorder')))
            ->when($notCarriedHere !== [], fn ($q) => $q->whereNotIn('id', $notCarriedHere))
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

    /** 043 — GET /products?tab=…: baris katalog + jumlah tab; lihat ProductCatalogQuery dan contracts/api-products.md. */
    private function catalog(\App\Http\Requests\ProductCatalogRequest $request): JsonResponse
    {
        $query = app(\App\Services\ProductCatalogQuery::class);
        $filters = $request->validated();
        $page = $query->page($filters, $request->user());

        return response()->json([
            'data' => $page->items(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
            'counts' => $query->counts(),
        ]);
    }

    /** 043 — GET /products/summary: empat angka header (sama dengan baris di bawahnya). */
    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        return response()->json(app(\App\Services\ProductCatalogQuery::class)->summary());
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $data = $request->validated();

        $outlet = Outlet::findOrFail($data['home_outlet_id']);
        $category = Category::findOrFail($data['category_id']);

        $segment = $data['product_segment'] ?? $this->codeGenerator->deriveSegmentFromName($data['name']);
        $codePrefix = $this->codeGenerator->buildCodePrefix($outlet->code, $category->code, $segment);

        $product = DB::transaction(function () use ($data, $segment, $codePrefix) {
            $product = Product::create([
                'home_outlet_id' => $data['home_outlet_id'],
                'category_id' => $data['category_id'],
                'station_id' => $data['station_id'] ?? null,
                'code_prefix' => $codePrefix,
                'product_segment' => strtoupper($segment),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_preorder' => $data['is_preorder'] ?? false,
                'preorder_eta' => $data['preorder_eta'] ?? null,
                // 043 — dengan rilis menu AKTIF, item baru berstatus draft (belum terbit) sampai rilis berikutnya;
                // dengan rilis mati perilaku lama: langsung aktif.
                'is_active' => \App\Support\MenuSettings::releaseEnabled() ? false : ($data['is_active'] ?? true),
                // 043 — fakta item.
                'short_name' => $data['short_name'] ?? null,
                'tax_category' => $data['tax_category'] ?? null,
                'prep_minutes' => $data['prep_minutes'] ?? null,
                'is_retail' => $data['is_retail'] ?? false,
            ]);

            foreach ($data['variants'] as $variantInput) {
                $sku = $this->codeGenerator->nextVariantSku($product);
                $product->variants()->create([
                    'sku' => $sku,
                    'variant_name' => $variantInput['variant_name'],
                    'cost_price' => $variantInput['cost_price'] ?? 0,
                    'sell_price' => $variantInput['sell_price'],
                    'low_stock_alert' => $variantInput['low_stock_alert'] ?? null,
                    // 028 — default true: perilaku lama tak berubah.
                    'track_stock' => $variantInput['track_stock'] ?? true,
                ]);
            }

            // 043 — dengan rilis AKTIF, penerbitan item baru menunggu rilis berikutnya (menu_change item_publish).
            if (\App\Support\MenuSettings::releaseEnabled()) {
                app(\App\Services\ProductLifecycleService::class)->requestPublish($product, request()->user());
            }

            $this->applyCarried($product, $data);

            return $product;
        });

        return response()->json(new ProductResource($product->load(['homeOutlet', 'category', 'outletItemSettings.outlet', ...$this->variantRelations($request)])), 201);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->authorize('view', $product);

        // json_decode(json_encode()) = mengurai resource bersarang persis seperti yang dilihat klien (tanpa pembungkus 'data').
        $payload = json_decode(json_encode(new ProductResource($product->load(['homeOutlet', 'category', 'outletItemSettings.outlet', ...$this->variantRelations($request)]))), true);

        // 043 — halaman item: fakta, stasiun, tautan modifier efektif, ketersediaan, rilis, `can`.
        $payload = array_merge($payload, app(\App\Services\ProductDetailService::class)->extras($product, $request->user()));

        // Biaya hanya untuk pengguna yang boleh melihatnya (menu products/reports) — pemanggil lain (mis. kasir) tidak.
        if (! ($request->user()->canAccessMenu('products') || $request->user()->canAccessMenu('reports'))) {
            $payload['variants'] = array_map(fn ($v) => array_diff_key($v, array_flip(['cost_price', 'has_bom', 'bom_cost'])), $payload['variants'] ?? []);
        }

        return response()->json($payload);
    }

    /** 043 — GET /products/{id}/price-history: riwayat harga dasar semua varian, terbaru dulu; baris terakhir = pembuatan. */
    public function priceHistory(Request $request, Product $product): JsonResponse
    {
        $this->authorize('view', $product);

        $rows = \App\Models\PriceHistory::query()
            ->whereIn('variant_id', $product->variants()->withTrashed()->pluck('id'))
            // 045: riwayat harga outlet hanya untuk outlet yang boleh dilihat pemanggil (NULL = harga dasar, selalu tampil).
            ->where(fn ($q) => $q->whereNull('outlet_id')->orWhereIn('outlet_id', app(\App\Support\OutletContext::class)->permittedIds()))
            ->with(['variant:id,variant_name', 'user:id,name', 'approver:id,name', 'outlet:id,code'])
            ->orderByDesc('effective_at')->orderByDesc('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        $versions = \App\Models\MenuRelease::query()->whereIn('id', $rows->pluck('release_id')->filter())->pluck('version', 'id');

        return response()->json([
            'data' => $rows->map(fn ($h) => [
                'at' => $h->effective_at->toIso8601String(),
                'variant' => $h->variant?->variant_name,
                'outlet_id' => $h->outlet_id,
                'outlet_code' => $h->outlet?->code,
                'old_price' => $h->old_price === null ? null : number_format((float) $h->old_price, 2, '.', ''),
                'new_price' => number_format((float) $h->new_price, 2, '.', ''),
                'source' => $h->source,
                'by' => $h->user?->name,
                'approved_by' => $h->approver?->name,
                'release_version' => $h->release_id ? ($versions[$h->release_id] ?? null) : null,
                'note' => $h->note,
            ])->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'last_page' => $rows->lastPage()],
        ]);
    }

    /**
     * 034-seller-po-bom — baris BOM ikut dimuat (untuk has_bom/bom_cost pada
     * varian) HANYA bagi pengguna yang boleh mengelola produk; daftar
     * produk/POS tidak pernah menariknya. ProductVariantResource menyajikan
     * field itu hanya bila relasinya termuat (relationLoaded), jadi yang
     * memuatnya harus eksplisit di sini.
     *
     * @return array<int, string>
     */
    private function variantRelations(Request $request): array
    {
        return $request->user()?->canAccessMenu('products') ? ['variants.bomLines.material'] : ['variants'];
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        // F13.4 — "ubah harga" mencakup update produk/varian secara umum;
        // snapshot before/after ditulis apa adanya (bukan hanya kalau harga
        // ikut berubah) supaya activity_logs tetap satu sumber audit yang
        // konsisten untuk seluruh perubahan master data produk.
        DB::transaction(function () use ($request, $product) {
            $before = $product->only($product->getFillable());

            $product->update(collect($request->validated())->except('outlet_ids')->all());
            $this->applyCarried($product, $request->validated());

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

        return response()->json(new ProductResource($product->fresh(['homeOutlet', 'category', 'outletItemSettings.outlet', ...$this->variantRelations($request)])));
    }

    /**
     * 045 Stage 3 — `outlet_ids` = outlet yang membawa produk, HANYA di antara outlet yang boleh dilihat pengguna
     * (outlet lain tidak disentuh). Tanpa `outlet_ids` tidak ada yang berubah.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyCarried(Product $product, array $data): void
    {
        if (! array_key_exists('outlet_ids', $data)) {
            return;
        }

        app(\App\Services\OutletMenuService::class)->setCarried(
            $product,
            array_map('intval', $data['outlet_ids']),
            app(\App\Support\OutletContext::class)->permittedIds(),
        );
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        $hasActiveVariants = $product->variants()->where('is_active', true)->exists();

        if ($hasActiveVariants) {
            return response()->json([
                'message' => __('master_data.product_delete_has_active_variants'),
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
        $copyFromId = $request->validated('copy_bom_from_variant_id');

        // Menyalin BOM menyentuh data PO (harga beli, vendor) — butuh kedua
        // menu, sama dengan semua aksi BOM lain (VariantBomController).
        if ($copyFromId && ! ($request->user()->canAccessMenu('products') && $request->user()->canAccessMenu('purchase_orders'))) {
            return response()->json(['message' => __('bom.not_authorized')], 403);
        }

        $sku = $this->codeGenerator->nextVariantSku($product);

        // Varian + salinan BOM dalam satu transaksi: salinan yang ditolak
        // (produk lain, sumber kosong) tidak boleh meninggalkan varian setengah jadi.
        try {
            $variant = DB::transaction(function () use ($request, $product, $sku, $copyFromId) {
                $variant = $product->variants()->create([
                    'sku' => $sku,
                    'variant_name' => $request->validated('variant_name'),
                    'cost_price' => $request->validated('cost_price') ?? 0,
                    'sell_price' => $request->validated('sell_price'),
                    'low_stock_alert' => $request->validated('low_stock_alert'),
                    'track_stock' => $request->validated('track_stock') ?? true,
                ]);

                if ($copyFromId) {
                    $this->bomService->copy(ProductVariant::findOrFail($copyFromId), [$variant], false, $request->user());
                }

                return $variant;
            });
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status);
        }

        return response()->json(new ProductVariantResource($variant), 201);
    }

    public function updateVariant(UpdateVariantRequest $request, ProductVariant $variant): JsonResponse
    {
        // 034-seller-po-bom — selama BOM varian SELESAI, cost_price mengikuti
        // biaya BOM dan tidak boleh diubah tangan. Nilai yang SAMA diterima
        // (SPA mengirim seluruh varian); yang berbeda ditolak 409.
        if ($variant->bom_complete
            && $request->has('cost_price')
            && ReportSplit::cents($request->validated('cost_price')) !== ReportSplit::cents($variant->cost_price)) {
            return response()->json([
                'message' => __('bom.cost_price_locked'),
                'code' => 'cost_price_locked_by_bom',
            ], 409);
        }

        // 043 — sama untuk varian yang digerakkan RESEP: biaya mengikuti resep aktifnya.
        if ($request->has('cost_price')
            && ReportSplit::cents($request->validated('cost_price')) !== ReportSplit::cents($variant->cost_price)
            && app(\App\Services\RecipeService::class)->isRecipeDriven($variant)) {
            return response()->json([
                'message' => __('products.recipe_cost_locked'),
                'code' => 'cost_price_locked_by_recipe',
            ], 409);
        }

        // F13.4 — "ubah harga" (price_changed): satu-satunya endpoint yang
        // menyentuh cost_price/sell_price. Snapshot ditulis apa adanya
        // (bukan hanya diff kolom harga) supaya operator bisa melihat
        // konteks lengkap perubahan, bukan cuma angka harga saja.
        // 043 — harga dasar HANYA ditulis lewat PriceService (riwayat harga + mode rilis/persetujuan). Bidang
        // lain tetap lewat update biasa; baris audit `price_changed` lama dipertahankan apa adanya.
        $price = ['changed' => false, 'applied' => false, 'pending' => false, 'requires_approval' => false, 'change' => null];

        DB::transaction(function () use ($request, $variant, &$price) {
            $before = $variant->only($variant->getFillable());
            $data = $request->validated();
            $hasPrice = array_key_exists('sell_price', $data);
            $newPrice = $data['sell_price'] ?? null;
            unset($data['sell_price']);

            $variant->update($data);
            if ($hasPrice) {
                $price = app(\App\Services\PriceService::class)->setBasePrice($variant, $newPrice, $request->user(), 'edit', [
                    'audit' => false, 'effective_from' => $request->input('effective_from'),
                ]);
            }

            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'price_changed',
                entityType: 'ProductVariant',
                entityId: $variant->id,
                description: "Mengubah varian {$variant->sku}.",
                oldValues: $before,
                newValues: $variant->fresh()->only($variant->getFillable()),
            );
        });

        $payload = json_decode(json_encode(new ProductVariantResource($variant->fresh())), true);
        if ($price['pending']) {
            // Rilis aktif / tanggal berlaku di depan: harga BELUM berubah; kembalikan perubahan yang menunggu.
            $payload['pending_change'] = ['id' => $price['change']->id, 'status' => $price['change']->status, 'new_price' => number_format((float) $price['change']->payload['new_price'], 2, '.', '')];
        }

        return response()->json($payload);
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

        return response()->json(new ProductResource($product->fresh(['homeOutlet', 'category', 'variants'])));
    }

    /**
     * Per-variant image — added at the product owner's explicit request so
     * different variants of the same product (e.g. different designs/motifs)
     * can each show their own picture, instead of all sharing the parent
     * product's single image_path. Mirrors uploadImage() exactly; authorize
     * against the variant's PARENT product, same as UpdateVariantRequest
     * does, since ProductVariant has no policy of its own.
     */
    public function uploadVariantImage(Request $request, ProductVariant $variant): JsonResponse
    {
        $this->authorize('update', $variant->product);

        $validated = $request->validate([
            'image' => [
                'required',
                'file',
                Rule::file()->max(ImageUploadService::MAX_KILOBYTES)->rules(['mimes:jpeg,png']),
            ],
        ]);

        $oldPath = $variant->image_path;

        $variant->image_path = $this->imageUploadService->store($validated['image'], 'variants');
        $variant->save();

        $this->imageUploadService->delete($oldPath);

        return response()->json(new ProductVariantResource($variant->fresh()));
    }

    /**
     * 024-invoice-layout-shipping-slip — `q` kosong SEKARANG mengembalikan
     * halaman default (bukan array kosong), meniru pola GET /customers
     * yang sudah ada (CustomerSearchDropdown.vue menampilkan daftar
     * default saat dibuka, bukan hanya setelah mengetik) — dipakai
     * PreordersView.vue supaya "Add item" berperilaku sama dengan field
     * pelanggan: klik untuk lihat daftar yang bisa dijelajahi, lalu
     * mengetik untuk menyaring.
     */
    public function lookupVariants(Request $request): JsonResponse
    {
        $term = (string) $request->query('q', '');
        $limit = min((int) $request->integer('limit', 20), 50);

        // 045 Stage 4 — `pos=1`: sama dengan daftar POS (outlet shift; item yang tidak dibawa tidak muncul; harga outlet).
        $orderingOutlet = $request->boolean('pos') ? app(\App\Support\OutletContext::class)->orderingId() : null;
        // 045 Stage 5 — `stock_outlet` (layar Stok): saldo di outlet itu (harus boleh dilihat) tanpa memfilter menu.
        $stockOutlet = $request->filled('stock_outlet') && app(\App\Support\OutletContext::class)->isPermitted($request->integer('stock_outlet')) ? $request->integer('stock_outlet') : null;
        app(\App\Support\OutletPriceBook::class)->useOrderingOutlet($orderingOutlet ?? $stockOutlet);
        $notCarriedHere = $orderingOutlet === null ? [] : \App\Models\OutletItemSetting::query()
            ->where('outlet_id', $orderingOutlet)->where('is_carried', false)->pluck('product_id')->all();

        $variants = ProductVariant::query()
            ->with(['product.homeOutlet', 'product.category'])
            ->where('is_active', true)
            ->when($notCarriedHere !== [], fn ($q) => $q->whereNotIn('product_id', $notCarriedHere))
            // 043 — produk yang didiskontinu tidak lagi bisa dipilih (mis. untuk pre-order baru).
            ->whereHas('product', fn ($p) => $p->where('is_active', true))
            ->when($term !== '', fn ($q) => $q->where(function ($q) use ($term) {
                $q->where('sku', 'like', "%{$term}%")
                  ->orWhere('variant_name', 'like', "%{$term}%")
                  ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%"));
            }))
            ->orderBy('sku')
            ->limit($limit)
            ->get();

        $data = $variants->map(fn (ProductVariant $v) => [
            'variant_id' => $v->id,
            'sku' => $v->sku,
            'label' => $v->product->name.' — '.$v->variant_name,
            'home_outlet_name' => $v->product->homeOutlet->name,
            // Requested for the preorder "Add item" dropdown/item list, so
            // staff can tell apart same-named products across categories
            // without opening each one.
            'category_name' => $v->product->category?->name,
            'sell_price' => number_format((float) $v->sell_price, 2, '.', ''),
            'outlet_price' => ($op = app(\App\Support\OutletPriceBook::class)->forCurrentOutlet($v->id)) === null ? null : number_format($op, 2, '.', ''),
            'current_stock' => $v->current_stock,
            'outlet_stock' => app(\App\Support\OutletPriceBook::class)->orderingStockOrNull($v->id),
            'is_preorder' => (bool) $v->product->is_preorder,
            // Falls back to the PRODUCT's own image when this variant has
            // none of its own — same accessor ProductVariant::image_url now
            // exposes to every caller (see its docblock), not duplicated
            // here anymore.
            'image_url' => $v->image_url,
        ]);

        return response()->json(['data' => $data]);
    }
}
