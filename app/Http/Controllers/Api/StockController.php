<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockAdjustmentRequest;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\StockMovementReferences;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function __construct(private StockService $stockService, private StockMovementReferences $references) {}

    /**
     * Dipakai layar Stok DAN riwayat transaksi per varian (036). Dulu hanya
     * terautentikasi — peran apa pun bisa membacanya; sekarang menu `stock`
     * atau `products` (riwayat varian hidup di bawah Produk), sehingga nama
     * pengguna pencatat tidak bocor ke peran yang tidak berhak.
     */
    public function movements(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->canAccessMenu('stock') && ! $user->canAccessMenu('products')) {
            return response()->json(['message' => __('stock.not_authorized')], 403);
        }

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $movements = StockMovement::query()
            // Varian/produk yang sudah dihapus tetap harus tampil di riwayatnya.
            ->with(['user:id,name', 'variant' => fn ($q) => $q->withTrashed(), 'variant.product' => fn ($q) => $q->withTrashed()])
            ->when($request->filled('variant_id'), fn ($q) => $q->where('variant_id', $request->integer('variant_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('date_to')))
            ->orderByDesc('created_at')
            ->orderByDesc('id') // timestamp kembar (satu transaksi, banyak baris) tetap berurutan stabil
            ->paginate($perPage);

        $references = $this->references->resolve(collect($movements->items()));

        $data = collect($movements->items())->map(fn (StockMovement $m) => [
            'id' => $m->id,
            'variant_id' => $m->variant_id,
            'sku' => $m->variant->sku,
            'type' => $m->type,
            'qty_change' => $m->qty_change,
            'stock_before' => $m->stock_before,
            'stock_after' => $m->stock_after,
            'reason' => $m->reason,
            'created_at' => $m->created_at,
            'user_name' => $m->user?->name,
            'variant_name' => $m->variant->variant_name,
            // null bila produknya sudah dihapus: layar Stok memberi pesan jelas, bukan dialog rusak.
            'product_id' => $m->variant->product && ! $m->variant->product->trashed() ? $m->variant->product->id : null,
            'product_name' => $m->variant->product?->name,
            'reference' => $references[$m->id] ?? null,
        ]);

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $movements->currentPage(),
                'per_page' => $movements->perPage(),
                'total' => $movements->total(),
                'last_page' => $movements->lastPage(),
            ],
        ]);
    }

    public function adjust(StockAdjustmentRequest $request): JsonResponse
    {
        $movements = [];

        foreach ($request->validated('items') as $item) {
            $variant = ProductVariant::findOrFail($item['variant_id']);
            $movements[] = $this->stockService->applyMovement(
                variant: $variant,
                type: 'adjustment',
                qtyChange: $item['qty_change'],
                reason: $request->validated('reason'),
                userId: $request->user()->id,
            );
        }

        return response()->json(['movements' => $movements], 201);
    }

    public function lowStock(Request $request): JsonResponse
    {
        $variants = ProductVariant::query()
            ->whereNotNull('low_stock_alert')
            ->whereColumn('current_stock', '<=', 'low_stock_alert')
            ->where('is_active', true)
            ->with('product')
            ->get();

        $data = $variants->map(fn (ProductVariant $v) => [
            'variant_id' => $v->id,
            'sku' => $v->sku,
            'product_name' => $v->product->name,
            'current_stock' => $v->current_stock,
            'low_stock_alert' => $v->low_stock_alert,
        ]);

        return response()->json(['data' => $data]);
    }
}
