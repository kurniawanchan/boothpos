<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePurchaseOrderRequest;
use App\Http\Requests\UpdatePurchaseOrderRequest;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PurchaseOrderController extends Controller
{
    public function __construct(private PurchaseOrderService $purchaseOrderService) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $orders = PurchaseOrder::query()
            ->with('vendor')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('vendor_id'), fn ($q) => $q->where('vendor_id', $request->integer('vendor_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('date_to')))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($orders->items())->map(fn (PurchaseOrder $po) => $this->present($po)),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $po = $this->purchaseOrderService->create($request->validated(), $request->user());

        return response()->json($this->present($po), 201);
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('view', $purchaseOrder);

        return response()->json($this->present($purchaseOrder->load(['items.material', 'items.product', 'vendor', 'payments'])));
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        try {
            $po = $this->purchaseOrderService->update($purchaseOrder, $request->validated());
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 409);
        }

        return response()->json($this->present($po));
    }

    public function destroy(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('delete', $purchaseOrder);

        try {
            $this->purchaseOrderService->delete($purchaseOrder, $request->user());
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 409);
        }

        return response()->json(null, 204);
    }

    public function updateStatus(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('update', $purchaseOrder);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['ordered', 'received', 'paid', 'cancelled'])],
            'cancel_reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $po = $this->purchaseOrderService->transitionStatus(
                $purchaseOrder, $validated['status'], $validated['cancel_reason'] ?? null, $request->user()
            );
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 409);
        }

        return response()->json($this->present($po));
    }

    public function storePayment(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('update', $purchaseOrder);

        $validated = $request->validate([
            'method' => ['required', 'in:cash,bank_transfer,qr_ewallet'],
            'channel_id' => ['nullable', 'integer', 'exists:payment_channels,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'proof_token' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $po = $this->purchaseOrderService->recordPayment($purchaseOrder, $validated, $request->user());
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        return response()->json($this->present($po), 201);
    }

    /**
     * Data mentah untuk invoice — PDF-nya sendiri dirender di klien
     * (html2canvas + jsPDF, pola yang sama dengan ReceiptModal.vue), lihat
     * research.md R6. Endpoint ini murni menyediakan data, bukan file.
     */
    public function invoice(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('view', $purchaseOrder);

        return response()->json($this->present($purchaseOrder->load(['items.material', 'items.product', 'vendor', 'payments'])));
    }

    private function present(PurchaseOrder $po): array
    {
        return [
            'id' => $po->id,
            'po_number' => $po->po_number,
            'vendor_id' => $po->vendor_id,
            'vendor_name' => $po->vendor?->name,
            'status' => $po->status,
            'ordered_at' => $po->ordered_at?->toIso8601String(),
            'received_at' => $po->received_at?->toIso8601String(),
            'paid_at' => $po->paid_at?->toIso8601String(),
            'cancelled_at' => $po->cancelled_at?->toIso8601String(),
            'cancel_reason' => $po->cancel_reason,
            'subtotal' => number_format((float) $po->subtotal, 2, '.', ''),
            'total_amount' => number_format((float) $po->total_amount, 2, '.', ''),
            'paid_amount' => number_format((float) $po->paidAmount(), 2, '.', ''),
            'notes' => $po->notes,
            'items' => $po->relationLoaded('items') ? $po->items->map(fn ($item) => [
                'id' => $item->id,
                'line_type' => $item->line_type,
                'material_id' => $item->material_id,
                'material_name' => $item->relationLoaded('material') ? $item->material?->name : null,
                'product_id' => $item->product_id,
                'product_name' => $item->relationLoaded('product') ? $item->product?->name : null,
                'description' => $item->description,
                'qty' => (string) $item->qty,
                'unit_price' => number_format((float) $item->unit_price, 2, '.', ''),
                'line_total' => number_format((float) $item->line_total, 2, '.', ''),
            ]) : [],
            'payments' => $po->relationLoaded('payments') ? $po->payments->map(fn ($p) => [
                'id' => $p->id,
                'method' => $p->method,
                'amount' => number_format((float) $p->amount, 2, '.', ''),
                'notes' => $p->notes,
                'paid_at' => $p->paid_at?->toIso8601String(),
            ]) : [],
            'created_at' => $po->created_at?->toIso8601String(),
        ];
    }
}
