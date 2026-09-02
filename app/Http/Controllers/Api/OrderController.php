<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Setting;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function __construct(private OrderService $orderService) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 25), 100);

        $orders = Order::query()
            ->when($request->filled('event_id'), fn ($q) => $q->where('event_id', $request->integer('event_id')))
            ->when($request->filled('session_id'), fn ($q) => $q->where('session_id', $request->integer('session_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('date_to')))
            ->withCount('items')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => OrderResource::collection($orders->items()),
            'meta' => [
                'current_page' => $orders->currentPage(), 'per_page' => $orders->perPage(),
                'total' => $orders->total(), 'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        try {
            $order = $this->orderService->create($request->validated(), $request->user());
        } catch (ValidationException $e) {
            // Aturan bisnis (stok tidak cukup, pembayaran kurang) dipetakan
            // ke 409 sesuai kontrak, bukan 422 generik — 422 dipakai untuk
            // kesalahan bentuk data, 409 untuk konflik aturan bisnis.
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 409);
        }

        return response()->json(new OrderResource($order), 201);
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json(new OrderResource($order->load(['items', 'payments'])));
    }

    public function void(Request $request, Order $order): JsonResponse
    {
        // Sama seperti catatan di EventPolicy::create() — "batalkan
        // transaksi" adalah gerbang per-aksi di dalam menu 'sales'/'pos'
        // yang dibagi semua peran, bukan aksesnya sendiri. Dipetakan ke
        // canAccessMenu('settings') untuk alasan yang sama (satu-satunya
        // kunci menu yang, pada keempat peran default, persis berisi
        // owner+admin).
        if (! $request->user()->canAccessMenu('settings')) {
            return response()->json(['message' => 'Hanya owner/admin yang dapat membatalkan transaksi.'], 403);
        }

        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $order = $this->orderService->void($order, $validated['reason'], $request->user());
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 409);
        }

        return response()->json(new OrderResource($order));
    }

    public function receipt(Order $order): JsonResponse
    {
        $order->load(['items', 'payments', 'cashier', 'event']);

        return response()->json([
            'store_name' => Setting::get('store_name', 'Toko'),
            'store_contact' => Setting::get('store_contact'),
            'order_number' => $order->order_number,
            'event_name' => $order->event->name,
            'cashier_name' => $order->cashier->name,
            'created_at' => $order->created_at,
            'items' => $order->items->map(fn ($i) => [
                'name' => $i->name_snapshot, 'qty' => $i->qty,
                'price' => number_format((float) $i->sell_price, 2, '.', ''),
                'line_total' => number_format((float) $i->line_total, 2, '.', ''),
            ]),
            'subtotal' => number_format((float) $order->subtotal, 2, '.', ''),
            'discount_amount' => number_format((float) $order->discount_amount, 2, '.', ''),
            'total_amount' => number_format((float) $order->total_amount, 2, '.', ''),
            'payment_summary' => $order->payments->map(fn ($p) => [
                'method' => $p->method, 'amount' => number_format((float) $p->amount, 2, '.', ''),
            ]),
            'change_amount' => number_format((float) $order->change_amount, 2, '.', ''),
        ]);
    }
}
