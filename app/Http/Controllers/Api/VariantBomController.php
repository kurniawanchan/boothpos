<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BomRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CopyBomRequest;
use App\Http\Requests\ReplaceBomSourceRequest;
use App\Http\Requests\StoreBomItemsRequest;
use App\Http\Requests\UpdateBomItemRequest;
use App\Http\Requests\UpdateBomQuantitiesRequest;
use App\Models\ProductVariant;
use App\Models\ProductVariantBomLine;
use App\Models\PurchaseOrderItem;
use App\Services\VariantBomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 034-seller-po-bom — BOM varian yang disusun dari baris purchase order.
 * Controller hanya memvalidasi, mendelegasikan ke VariantBomService (satu-
 * satunya penulis BOM) dan membentuk respons.
 */
class VariantBomController extends Controller
{
    public function __construct(private VariantBomService $bomService) {}

    /**
     * Membaca BOM cukup menu 'products'. SEMUA aksi lain (selector, tambah,
     * ubah, hapus, ganti sumber, salin, selesai/buka) juga butuh menu
     * 'purchase_orders': selector menampilkan harga beli dan vendor PO,
     * data yang oleh PurchaseOrderPolicy dikhususkan bagi menu itu. Satu
     * tempat gerbang supaya aksi baru tidak lupa memasangnya.
     */
    private function authorizeBom(Request $request, bool $mutating): ?JsonResponse
    {
        $user = $request->user();
        $allowed = $user->canAccessMenu('products') && (! $mutating || $user->canAccessMenu('purchase_orders'));

        return $allowed ? null : response()->json(['message' => __('bom.not_authorized')], 403);
    }

    private function conflict(ValidationException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status);
    }

    private function ruleViolation(BomRuleException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'code' => $e->reason] + $e->context, $e->status);
    }

    public function index(Request $request, ProductVariant $variant): JsonResponse
    {
        if ($denied = $this->authorizeBom($request, false)) {
            return $denied;
        }

        return response()->json($this->bomService->payload($variant));
    }

    /**
     * Baris PO yang boleh dipilih untuk BOM varian ini: hanya milik seller
     * varian ini dan berstatus ordered/received/paid (scope tunggal
     * PurchaseOrderItem::eligibleForSeller). `in_bom` menandai baris yang
     * sudah dipakai BOM varian INI supaya tidak ditambahkan dua kali.
     */
    public function eligibleLines(Request $request, ProductVariant $variant): JsonResponse
    {
        if ($denied = $this->authorizeBom($request, true)) {
            return $denied;
        }

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'purchase_order' => ['nullable', 'string', 'max:50'],
            'vendor_id' => ['nullable', 'integer'],
            'line_type' => ['nullable', 'in:material,service'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $variant->loadMissing('product');
        $perPage = min((int) ($filters['per_page'] ?? 25), 100);

        $lines = PurchaseOrderItem::query()
            ->eligibleForSeller((int) $variant->product->artist_id)
            ->with(['purchaseOrder.vendor', 'material'])
            ->when($filters['line_type'] ?? null, fn ($q, $type) => $q->where('line_type', $type))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->where('description', 'like', "%{$term}%")
                ->orWhereHas('material', fn ($m) => $m->where('name', 'like', "%{$term}%"))))
            ->when($filters['purchase_order'] ?? null, fn ($q, $number) => $q
                ->whereHas('purchaseOrder', fn ($po) => $po->where('po_number', 'like', "%{$number}%")))
            ->when($filters['vendor_id'] ?? null, fn ($q, $vendorId) => $q
                ->whereHas('purchaseOrder', fn ($po) => $po->where('vendor_id', $vendorId)))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q
                ->whereHas('purchaseOrder', fn ($po) => $po->whereRaw('DATE(COALESCE(ordered_at, created_at)) >= ?', [$date])))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q
                ->whereHas('purchaseOrder', fn ($po) => $po->whereRaw('DATE(COALESCE(ordered_at, created_at)) <= ?', [$date])))
            ->orderByDesc('id')
            ->paginate($perPage);

        $inBom = $variant->bomLines()->whereNotNull('purchase_order_item_id')->pluck('purchase_order_item_id')->flip();

        return response()->json([
            'data' => collect($lines->items())->map(fn (PurchaseOrderItem $item) => [
                'purchase_order_item_id' => $item->id,
                'purchase_order_id' => $item->purchase_order_id,
                'po_number' => $item->purchaseOrder->po_number,
                'po_status' => $item->purchaseOrder->status,
                'po_date' => ($item->purchaseOrder->ordered_at ?? $item->purchaseOrder->created_at)?->toIso8601String(),
                'vendor_id' => $item->purchaseOrder->vendor_id,
                'vendor_name' => $item->purchaseOrder->vendor?->name,
                'line_type' => $item->line_type,
                'item_name' => $item->line_type === 'material' ? $item->material?->name : $item->description,
                'material_id' => $item->material_id,
                'unit_price' => number_format((float) $item->unit_price, 2, '.', ''),
                'po_qty' => (float) $item->qty,
                'in_bom' => $inBom->has($item->id),
            ])->values(),
            'meta' => [
                'current_page' => $lines->currentPage(),
                'per_page' => $lines->perPage(),
                'total' => $lines->total(),
                'last_page' => $lines->lastPage(),
            ],
        ]);
    }

    public function storeItems(StoreBomItemsRequest $request, ProductVariant $variant): JsonResponse
    {
        if ($denied = $this->authorizeBom($request, true)) {
            return $denied;
        }

        try {
            $this->bomService->addItems($variant, $request->validated('items'), $request->user());
        } catch (ValidationException $e) {
            return $this->conflict($e);
        }

        return response()->json($this->bomService->payload($variant), 201);
    }

    public function update(UpdateBomItemRequest $request, ProductVariantBomLine $bomLine): JsonResponse
    {
        if ($denied = $this->authorizeBom($request, true)) {
            return $denied;
        }

        $variant = $this->bomService->updateLine($bomLine, $request->validated(), $request->user());

        return response()->json($this->bomService->payload($variant));
    }

    /** 036 — tombol Simpan: banyak jumlah sekaligus, semua-atau-tidak-sama-sekali. */
    public function updateQuantities(UpdateBomQuantitiesRequest $request, ProductVariant $variant): JsonResponse
    {
        if ($denied = $this->authorizeBom($request, true)) {
            return $denied;
        }

        try {
            $variant = $this->bomService->updateQuantities($variant, $request->validated('lines'), $request->user());
        } catch (BomRuleException $e) {
            return $this->ruleViolation($e);
        }

        return response()->json($this->bomService->payload($variant));
    }

    public function destroy(Request $request, ProductVariantBomLine $bomLine): JsonResponse
    {
        if ($denied = $this->authorizeBom($request, true)) {
            return $denied;
        }

        $result = $this->bomService->removeLine($bomLine, $request->user());

        return response()->json($this->bomService->payload($result['variant'], $result['reopened']));
    }

    /** Tandai BOM selesai (harga modal mengikuti BOM). 409 + `code` bila belum memenuhi syarat. */
    public function complete(Request $request, ProductVariant $variant): JsonResponse
    {
        if ($denied = $this->authorizeBom($request, true)) {
            return $denied;
        }

        try {
            $variant = $this->bomService->complete($variant, $request->user());
        } catch (BomRuleException $e) {
            return $this->ruleViolation($e);
        }

        return response()->json($this->bomService->payload($variant));
    }

    public function reopen(Request $request, ProductVariant $variant): JsonResponse
    {
        if ($denied = $this->authorizeBom($request, true)) {
            return $denied;
        }

        $variant = $this->bomService->reopen($variant, $request->user());

        return response()->json($this->bomService->payload($variant));
    }

    /** Salin BOM varian lain KE varian ini (`mode=from`). */
    public function copyFrom(CopyBomRequest $request, ProductVariant $variant): JsonResponse
    {
        if ($denied = $this->authorizeBom($request, true)) {
            return $denied;
        }

        $request->validate(['mode' => ['in:from']]);
        $source = ProductVariant::findOrFail($request->integer('source_variant_id'));

        try {
            $results = $this->bomService->copy($source, [$variant], $request->boolean('confirm_replace'), $request->user());
        } catch (BomRuleException $e) {
            return $this->ruleViolation($e);
        } catch (ValidationException $e) {
            return $this->conflict($e);
        }

        return response()->json($this->bomService->payload($variant) + ['results' => $results]);
    }

    /** Salin BOM varian ini KE varian berikutnya, SEMUA varian lain, atau varian PILIHAN (037) produk yang sama. */
    public function copyOut(CopyBomRequest $request, ProductVariant $variant): JsonResponse
    {
        if ($denied = $this->authorizeBom($request, true)) {
            return $denied;
        }

        $request->validate(['mode' => ['in:next,all,selected']]);

        try {
            $results = $this->bomService->copy(
                $variant,
                $this->bomService->copyTargets($variant, $request->string('mode')->toString(), (array) $request->input('variant_ids', [])),
                $request->boolean('confirm_replace'),
                $request->user(),
            );
        } catch (BomRuleException $e) {
            return $this->ruleViolation($e);
        } catch (ValidationException $e) {
            return $this->conflict($e);
        }

        return response()->json(['results' => $results]);
    }

    /** Ganti sumber (baris PO) sebuah baris BOM — tindakan eksplisit, bukan pembaruan otomatis. */
    public function replaceSource(ReplaceBomSourceRequest $request, ProductVariantBomLine $bomLine): JsonResponse
    {
        if ($denied = $this->authorizeBom($request, true)) {
            return $denied;
        }

        try {
            $variant = $this->bomService->replaceSource($bomLine, PurchaseOrderItem::findOrFail($request->integer('purchase_order_item_id')), $request->user());
        } catch (ValidationException $e) {
            return $this->conflict($e);
        }

        return response()->json($this->bomService->payload($variant));
    }
}
