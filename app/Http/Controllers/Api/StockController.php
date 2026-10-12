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
    use \App\Http\Controllers\Concerns\ResolvesStockOutlet;

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
            ->with(['user:id,name', 'outlet:id,code,name', 'variant' => fn ($q) => $q->withTrashed(), 'variant.product' => fn ($q) => $q->withTrashed()])
            // 045 Stage 5 — hanya outlet yang berlaku (penugasan ∩ X-Outlet-Id); `outlet_id` menyempit lagi dan harus boleh dilihat.
            ->tap(fn ($q) => \App\Support\OutletFilter::apply($q, 'outlet_id'))
            ->when($request->filled('outlet_id'), fn ($q) => $q->where('outlet_id', $request->integer('outlet_id')))
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
            'outlet_id' => $m->outlet_id,
            'outlet_code' => $m->outlet?->code,
            'outlet_name' => $m->outlet?->name,
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
        $outletId = $this->stockOutlet($request, $request->validated('outlet_id'));
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $movements = [];

        foreach ($request->validated('items') as $item) {
            $variant = ProductVariant::findOrFail($item['variant_id']);
            $movements[] = $this->stockService->applyMovement(
                variant: $variant,
                outletId: $outletId,
                type: 'adjustment',
                qtyChange: $item['qty_change'],
                reason: $request->validated('reason'),
                userId: $request->user()->id,
            );
        }

        return response()->json(['movements' => $movements], 201);
    }

    /**
     * 045 Stage 5 — GET /stock/low: satu baris per (varian, outlet) yang saldonya ≤ ambang, dibatasi pada outlet yang
     * berlaku. `current_stock` = saldo di outlet itu. Definisi yang sama dengan angka Dashboard (LowStockHistory).
     */
    public function lowStock(Request $request): JsonResponse
    {
        $rows = \App\Services\LowStockHistory::currentQuery()
            ->with(['variant.product', 'outlet:id,code,name'])
            ->orderBy('variant_outlet_stock.variant_id')->orderBy('variant_outlet_stock.outlet_id')
            ->get(['variant_outlet_stock.*']);

        $data = $rows->map(fn ($b) => [
            'variant_id' => $b->variant_id,
            'sku' => $b->variant->sku,
            'product_name' => $b->variant->product->name,
            'outlet_id' => $b->outlet_id,
            'outlet_code' => $b->outlet?->code,
            'outlet_name' => $b->outlet?->name,
            'current_stock' => $b->current_stock,
            'low_stock_alert' => $b->variant->low_stock_alert,
        ]);

        return response()->json(['data' => $data]);
    }

    /**
     * 045 Stage 5 — GET /stock/balances?variant_id[]=&outlet_id=: saldo per (varian, outlet) pada outlet yang berlaku.
     * Menu `stock` atau `products` (sama dengan riwayat).
     */
    public function balances(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->canAccessMenu('stock') && ! $user->canAccessMenu('products')) {
            return response()->json(['message' => __('stock.not_authorized')], 403);
        }

        $variantIds = array_filter(array_map('intval', (array) $request->query('variant_id', [])));
        $rows = \App\Models\VariantOutletStock::query()
            ->with(['variant:id,sku,variant_name', 'outlet:id,code,name'])
            ->tap(fn ($q) => \App\Support\OutletFilter::apply($q, 'outlet_id'))
            ->when($request->filled('outlet_id'), fn ($q) => $q->where('outlet_id', $request->integer('outlet_id')))
            ->when($variantIds !== [], fn ($q) => $q->whereIn('variant_id', $variantIds))
            ->orderBy('variant_id')->orderBy('outlet_id')
            ->limit(2000)->get();

        return response()->json(['data' => $rows->map(fn ($b) => [
            'variant_id' => $b->variant_id,
            'sku' => $b->variant?->sku,
            'outlet_id' => $b->outlet_id,
            'outlet_code' => $b->outlet?->code,
            'outlet_name' => $b->outlet?->name,
            'on_hand' => $b->current_stock,
        ])->values()]);
    }
}
