<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockAdjustmentRequest;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function __construct(private StockService $stockService) {}

    public function movements(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 25), 100);

        $movements = StockMovement::query()
            ->with('variant')
            ->when($request->filled('variant_id'), fn ($q) => $q->where('variant_id', $request->integer('variant_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('date_to')))
            ->orderByDesc('created_at')
            ->paginate($perPage);

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
