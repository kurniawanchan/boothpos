<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePreorderRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Preorder;
use App\Services\PreorderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PreorderController extends Controller
{
    public function __construct(private PreorderService $preorderService) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 25), 100);

        $preorders = Preorder::query()
            ->with('customer')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('event_id'), fn ($q) => $q->where('event_id', $request->integer('event_id')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('fulfillment'), fn ($q) => $q->where('fulfillment', $request->string('fulfillment')))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $data = collect($preorders->items())->map(fn (Preorder $p) => [
            'id' => $p->id, 'preorder_number' => $p->preorder_number,
            'customer_name' => $p->customer->name, 'status' => $p->status,
            'fulfillment' => $p->fulfillment,
            'total_amount' => number_format((float) $p->total_amount, 2, '.', ''),
            'paid_amount' => number_format((float) $p->paid_amount, 2, '.', ''),
            'outstanding' => number_format($p->outstanding(), 2, '.', ''),
            'created_at' => $p->created_at,
        ]);

        return response()->json([
            'data' => $data,
            'meta' => ['current_page' => $preorders->currentPage(), 'per_page' => $preorders->perPage(),
                       'total' => $preorders->total(), 'last_page' => $preorders->lastPage()],
        ]);
    }

    public function store(StorePreorderRequest $request): JsonResponse
    {
        $preorder = $this->preorderService->create($request->validated(), $request->user());
        return response()->json($this->present($preorder), 201);
    }

    public function show(Preorder $preorder): JsonResponse
    {
        return response()->json($this->present($preorder->load(['items', 'payments', 'shipment', 'customer'])));
    }

    public function updateStatus(Request $request, Preorder $preorder): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:dp_paid,arrived,settled,handed_over,cancelled'],
            'cancel_reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $preorder = $this->preorderService->transitionStatus(
                $preorder, $validated['status'], $validated['cancel_reason'] ?? null, $request->user()
            );
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 409);
        }

        return response()->json($this->present($preorder));
    }

    public function storePayment(Request $request, Preorder $preorder): JsonResponse
    {
        $validated = $request->validate([
            'method' => ['required', 'in:cash,bank_transfer,qr_ewallet'],
            'channel_id' => ['nullable', 'integer', 'exists:payment_channels,id'],
            'purpose' => ['sometimes', 'in:full,down_payment,settlement'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'proof_token' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $preorder = $this->preorderService->recordPayment($preorder, $validated);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        return response()->json($this->present($preorder), 201);
    }

    private function present(Preorder $preorder): array
    {
        return [
            'id' => $preorder->id, 'preorder_number' => $preorder->preorder_number,
            'event_id' => $preorder->event_id, 'status' => $preorder->status,
            'fulfillment' => $preorder->fulfillment,
            'subtotal' => number_format((float) $preorder->subtotal, 2, '.', ''),
            'shipping_cost' => number_format((float) $preorder->shipping_cost, 2, '.', ''),
            'total_amount' => number_format((float) $preorder->total_amount, 2, '.', ''),
            'paid_amount' => number_format((float) $preorder->paid_amount, 2, '.', ''),
            'outstanding' => number_format($preorder->outstanding(), 2, '.', ''),
            'expected_date' => $preorder->expected_date?->toDateString(),
            'cancel_reason' => $preorder->cancel_reason,
            'items' => $preorder->relationLoaded('items') ? $preorder->items->map(fn ($i) => [
                'id' => $i->id, 'variant_id' => $i->variant_id, 'sku_snapshot' => $i->sku_snapshot,
                'name_snapshot' => $i->name_snapshot, 'qty' => $i->qty,
                'sell_price' => number_format((float) $i->sell_price, 2, '.', ''),
                'line_total' => number_format((float) $i->line_total, 2, '.', ''),
            ]) : [],
            // Sebelumnya hilang total dari present() meski show() sudah
            // meng-eager-load ketiganya (dan openapi-pos-mvp.yaml sudah lama
            // mendokumentasikan field ini) — layar detail preorder di
            // frontend tidak pernah bisa menampilkan riwayat pembayaran atau
            // data pengiriman. Ditemukan lewat verifikasi browser sungguhan
            // saat integrasi frontend, bukan lewat test yang sudah ada.
            'customer' => $preorder->relationLoaded('customer') && $preorder->customer
                ? new CustomerResource($preorder->customer) : null,
            'payments' => $preorder->relationLoaded('payments') ? $preorder->payments->map(fn ($p) => [
                'id' => $p->id, 'method' => $p->method, 'purpose' => $p->purpose,
                'amount' => number_format((float) $p->amount, 2, '.', ''),
                'verification' => $p->verification, 'paid_at' => $p->paid_at,
            ]) : [],
            'shipment' => $preorder->relationLoaded('shipment') && $preorder->shipment ? [
                'id' => $preorder->shipment->id,
                'courier_name' => $preorder->shipment->courier_name,
                'tracking_number' => $preorder->shipment->tracking_number,
                'shipping_cost' => number_format((float) $preorder->shipment->shipping_cost, 2, '.', ''),
                'recipient_name' => $preorder->shipment->recipient_name,
                'recipient_phone' => $preorder->shipment->recipient_phone,
                'address_line' => $preorder->shipment->address_line,
                'city' => $preorder->shipment->city,
                'province' => $preorder->shipment->province,
                'postal_code' => $preorder->shipment->postal_code,
                'status' => $preorder->shipment->status,
                'shipped_at' => $preorder->shipment->shipped_at,
                'delivered_at' => $preorder->shipment->delivered_at,
                'notes' => $preorder->shipment->notes,
            ] : null,
        ];
    }
}
