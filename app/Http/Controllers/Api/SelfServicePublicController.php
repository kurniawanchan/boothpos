<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitSelfServiceOrderRequest;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\ModifierGroup;
use App\Models\Outlet;
use App\Models\PaymentProof;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SelfServiceOrder;
use App\Http\Resources\SelfServiceReceiptResource;
use App\Services\ImageUploadService;
use App\Support\ModeGate;
use App\Services\ModifierResolver;
use App\Services\OutletMenuService;
use App\Services\SelfServiceOrderService;
use App\Support\MenuAvailability;
use App\Support\OutletPriceBook;
use App\Support\PosSettings;
use App\Support\StoreBranding;
use App\Support\StoreIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * 090 — rute publik pesan mandiri (tanpa token; pengecualian Konstitusi IV). Outlet dan meja datang dari token QR
 * yang sudah diverifikasi middleware `self-service.table` (atribut `self_service_table`), TIDAK PERNAH dari body.
 *
 * Hanya field DAFTAR-PUTIH yang keluar dari sini: tidak ada HPP, angka stok, SKU, outlet asal produk, bahan modifier,
 * data staf, atau data pelanggan (research D8).
 */
class SelfServicePublicController extends Controller
{
    private const PROOF_MAX_KILOBYTES = 5120; // 5 MB, sama dengan unggahan bukti kasir

    public function __construct(
        private SelfServiceOrderService $service,
        private ModifierResolver $modifiers,
        private ImageUploadService $images,
    ) {}

    public function context(Request $request): JsonResponse
    {
        $table = $this->table($request);
        $outlet = Outlet::query()->findOrFail($table->outlet_id);
        $channel = SelfServiceOrderService::activeQrisChannel();
        $identity = StoreIdentity::forScreen($outlet);

        return response()->json([
            'data' => [
                'outlet' => ['name' => $identity['name'] ?? $outlet->name, 'logo_url' => StoreBranding::logoUrl()],
                'table' => ['name' => $table->name],
                'available' => $channel !== null,
                'unavailable_reason' => $channel === null ? 'qris_unavailable' : null,
                // `rate` = tarif yang BENAR-BENAR dikenakan order baru (0 bila baris pajak dimatikan), sama dengan TaxCalculator.
                'tax' => ['label' => PosSettings::taxLabel(), 'rate' => PosSettings::effectiveTaxRate(), 'show' => PosSettings::taxShow()],
                'payment' => $channel === null ? null : [
                    'method' => 'qris',
                    'channel_id' => $channel->id,
                    'qr_image_url' => $this->images->url($channel->qr_image_path),
                ],
            ],
        ]);
    }

    public function menu(Request $request): JsonResponse
    {
        $table = $this->table($request);
        $outletId = (int) $table->outlet_id;

        // Harga/rilis berjadwal yang jatuh tempo diterapkan di titik masuk ini, sama dengan daftar POS (043).
        app(\App\Services\ReleaseService::class)->publishDueCached();
        app(\App\Services\RecipeService::class)->applyDueCached();

        $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:60'], 'category_id' => ['sometimes', 'nullable', 'integer']]);

        $book = app(OutletPriceBook::class);
        $notCarried = app(OutletMenuService::class)->notCarriedProductIds($outletId);
        $soldOut = array_flip(MenuAvailability::soldOutProductIds($outletId));

        $base = Product::query()
            ->where('is_active', true)->whereNull('discontinued_at')
            ->when($notCarried !== [], fn ($q) => $q->whereNotIn('id', $notCarried))
            ->whereHas('variants', fn ($v) => $v->where('is_active', true));

        $term = trim((string) $request->query('q', ''));
        $products = (clone $base)
            ->with([
                'category',
                'variants' => fn ($v) => $v->where('is_active', true)->orderBy('id'),
                'modifierGroups.options', 'category.modifierGroups.options', 'excludedModifierGroups',
            ])
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', (int) $request->query('category_id')))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$term.'%')
                ->orWhereHas('variants', fn ($v) => $v->where('variant_name', 'like', '%'.$term.'%'))))
            ->orderBy('name')
            ->get();

        $categoryIds = (clone $base)->pluck('category_id')->unique()->all();
        $categories = Category::query()->whereIn('id', $categoryIds)->where('is_active', true)->orderBy('display_order')->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'data' => [
                'categories' => $categories->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
                'products' => $products->map(fn (Product $p) => $this->presentProduct($p, $outletId, $book, isset($soldOut[$p->id])))->values(),
            ],
        ]);
    }

    /** Unggah bukti bayar: JPEG/PNG ≤ 5 MB, disk privat, nama acak, tanpa pengunggah (research D9). */
    public function uploadProof(Request $request): JsonResponse
    {
        $this->table($request);

        $validated = $request->validate([
            'file' => ['required', 'file', \Illuminate\Validation\Rules\File::default()->max(self::PROOF_MAX_KILOBYTES)->rules(['mimes:jpeg,png'])],
            'captured_via' => ['required', Rule::in(['webcam', 'upload'])],
        ], ['file.*' => __('self_service.proof_file_invalid')]);

        $file = $validated['file'];

        // Pemeriksaan MIME kedua dari ISI berkas (bukan ekstensi), sama dengan PaymentProofController.
        $mime = $file->getMimeType();
        if (! in_array($mime, ['image/jpeg', 'image/png'], true)) {
            return response()->json(['message' => __('self_service.proof_file_invalid'), 'errors' => ['file' => [__('self_service.proof_file_invalid')]]], 422);
        }

        $path = $file->storeAs('payment-proofs', Str::uuid()->toString().'.'.($mime === 'image/png' ? 'png' : 'jpg'), 'local');

        $proof = PaymentProof::create([
            'proof_token' => Str::uuid()->toString(),
            'payment_id' => null,
            'file_path' => $path,
            'original_name' => Str::limit(basename((string) $file->getClientOriginalName()), 100, ''),
            'mime_type' => $mime,
            'file_size' => $file->getSize(),
            'captured_via' => $validated['captured_via'],
            'uploaded_by' => null,
            'created_at' => now(),
        ]);

        return response()->json(['proof_token' => $proof->proof_token, 'file_size' => $proof->file_size], 201);
    }

    /**
     * 090 / US3 — struk pelanggan lewat token struk acak (40 karakter, bukan id). Tanpa token QR: pelanggan bisa membuka
     * ulang struknya kapan pun dari perangkat yang sama. Hanya mode data yang aktif; kiriman mode lain dianggap tidak ada.
     */
    public function receipt(Request $request, string $receiptToken): JsonResponse
    {
        $row = strlen($receiptToken) === 40
            ? SelfServiceOrder::withoutGlobalScopes()->with('order')->whereRaw('BINARY receipt_token = ?', [$receiptToken])->where('data_mode', ModeGate::current())->first()
            : null;

        if ($row === null) {
            return response()->json(['message' => __('self_service.receipt_not_found'), 'code' => 'receipt_not_found'], 404);
        }
        // Rute publik tanpa pengguna/outlet terikat: satu-satunya scope yang tersisa adalah yang sengaja dipasang di atas.
        app()->setLocale('id');

        return response()->json(['data' => new SelfServiceReceiptResource($row)]);
    }

    public function submit(SubmitSelfServiceOrderRequest $request): JsonResponse
    {
        $result = $this->service->submit($this->table($request), $request->validated());

        return response()->json(['data' => $this->presentSubmission($result['order'])], $result['replayed'] ? 200 : 201);
    }

    // --- bentuk keluaran (daftar putih) ------------------------------------------------------------

    /** @return array<string, mixed> */
    private function presentProduct(Product $product, int $outletId, OutletPriceBook $book, bool $soldOut): array
    {
        $variants = $product->variants->map(function (ProductVariant $v) use ($book, $outletId) {
            $price = $book->priceOrNull($v->id, $outletId) ?? round((float) $v->sell_price, 2);

            return ['id' => $v->id, 'name' => $v->variant_name, 'price' => number_format($price, 2, '.', '')];
        })->values();

        $prices = $variants->pluck('price')->map(fn ($p) => (float) $p);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'category_id' => $product->category_id,
            'category_name' => $product->category?->name,
            'image_url' => $this->images->url($product->image_path),
            'sold_out' => $soldOut,
            'price_from' => number_format((float) $prices->min(), 2, '.', ''),
            'price_to' => number_format((float) $prices->max(), 2, '.', ''),
            'variants' => $variants,
            'modifier_groups' => $this->presentGroups($product),
        ];
    }

    /**
     * Grup efektif dalam bentuk daftar-putih: TANPA bahan, saldo bahan, atau porsi (`ModifierResolver::present` memuat
     * `materials[].on_hand`, jadi tidak dipakai untuk publik).
     *
     * @return list<array<string, mixed>>
     */
    private function presentGroups(Product $product): array
    {
        return $this->modifiers->effectiveGroups($product)->map(fn (ModifierGroup $g) => [
            'id' => $g->id,
            'name' => $g->name,
            'selection_type' => $g->selection_type,
            'is_required' => (bool) $g->is_required,
            'min_select' => $g->min_select,
            'max_select' => $g->max_select,
            'portion_per_option' => (bool) $g->portion_per_option,
            'options' => $g->options->map(fn ($o) => [
                'id' => $o->id,
                'name' => $o->name,
                'price_delta' => number_format((float) $o->price_delta, 2, '.', ''),
                'min_qty' => max(1, (int) ($o->min_qty ?? 1)),
                'max_qty' => $o->max_qty === null ? null : (int) $o->max_qty,
            ])->values(),
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function presentSubmission(SelfServiceOrder $order): array
    {
        return [
            'receipt_token' => $order->receipt_token,
            'reference_no' => $order->reference_no,
            'status' => $order->status,
            'service_type' => $order->service_type,
            'table_name' => $order->table_name_snapshot,
            'subtotal' => number_format((float) $order->subtotal, 2, '.', ''),
            'tax_amount' => number_format((float) $order->tax_amount, 2, '.', ''),
            'total_amount' => number_format((float) $order->total_amount, 2, '.', ''),
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }

    private function table(Request $request): DiningTable
    {
        return $request->attributes->get('self_service_table');
    }
}
