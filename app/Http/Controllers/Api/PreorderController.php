<?php

namespace App\Http\Controllers\Api;

use App\Exports\GenericArrayExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePreorderRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Preorder;
use App\Services\PreorderExportImportService;
use App\Services\PreorderNotifier;
use App\Services\PreorderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class PreorderController extends Controller
{
    public function __construct(
        private PreorderService $preorderService,
        private PreorderExportImportService $exportImportService,
        private PreorderNotifier $notifier,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 25), 100);

        $preorders = Preorder::query()
            ->with('customer')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('event_id'), fn ($q) => $q->where('event_id', $request->integer('event_id')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('fulfillment'), fn ($q) => $q->where('fulfillment', $request->string('fulfillment')))
            // 007-preorder-import-export-notify (US1) — parsial, tidak peka
            // huruf besar/kecil, terhadap nama pelanggan (research.md R1).
            ->when($request->filled('search'), fn ($q) => $q->whereHas(
                'customer',
                fn ($cq) => $cq->where('name', 'like', '%' . $request->string('search')->value() . '%')
            ))
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
        $preorder->load(['items', 'payments', 'shipment', 'customer', 'notifications']);

        return response()->json([
            ...$this->present($preorder),
            // 007-preorder-import-export-notify (US4) — biar layar detail
            // yang sudah ada bisa menampilkan status notifikasi terakhir
            // tanpa request tambahan (data-model.md).
            'latest_notification' => $this->presentNotification($preorder->latestNotification()),
        ]);
    }

    /**
     * 007-preorder-import-export-notify (US2) — data mentah untuk
     * invoice/struk; PDF-nya sendiri dirender di klien (html2canvas +
     * jsPDF), sama seperti pola ReceiptModal.vue/PO invoice (research.md
     * R2). `document_type` dihitung SEKALI di sini via
     * PreorderDocumentType, dipakai ulang oleh email (US4) — tidak pernah
     * didefinisikan dua kali.
     */
    public function invoice(Preorder $preorder): JsonResponse
    {
        $preorder->load(['items', 'payments', 'customer']);

        return response()->json([
            ...$this->present($preorder),
            'document_type' => \App\Support\PreorderDocumentType::forStatus($preorder->status),
        ]);
    }

    /**
     * 007-preorder-import-export-notify (US3, FR-015) — export/import
     * dibatasi owner/admin, inline seperti ReportController/
     * CashierSessionController — bukan menu key baru, karena 'preorders'
     * masih dipakai bersama kasir/inventory untuk CRUD dasar.
     */
    public function export(Request $request)
    {
        abort_unless($request->user()->isOwnerOrAdmin(), 403, __('preorders.not_authorized'));

        $rows = $this->exportImportService->export($request->only([
            'status', 'event_id', 'customer_id', 'fulfillment', 'search', 'date_from', 'date_to',
        ]));

        return Excel::download(new GenericArrayExport($rows), 'preorders.xlsx');
    }

    public function importTemplate(Request $request)
    {
        abort_unless($request->user()->isOwnerOrAdmin(), 403, __('preorders.not_authorized'));

        return Excel::download(new GenericArrayExport($this->exportImportService->template()), 'template-preorders.xlsx');
    }

    public function import(Request $request): JsonResponse
    {
        abort_unless($request->user()->isOwnerOrAdmin(), 403, __('preorders.not_authorized'));

        $request->validate(['file' => ['required', 'file', 'mimes:xlsx']]);

        $result = $this->exportImportService->import($request->file('file'), $request->boolean('dry_run'), $request->user());

        if (! $result['applied'] && ! $result['dry_run']) {
            return response()->json([
                'message' => __('preorders.import_nothing_saved'),
                'row_errors' => $result['row_errors'],
            ], 409);
        }

        return response()->json([
            'created_count' => $result['created_count'],
            'created_customer_count' => $result['created_customer_count'],
            'preorder_ids' => $result['preorder_ids'],
        ], $result['dry_run'] ? 200 : 201);
    }

    /**
     * 007-preorder-import-export-notify (US4, FR-014) — jalur yang sama
     * persis dengan notifikasi otomatis saat status berubah, dipicu
     * manual. SELALU 200 dengan hasil percobaan (bukan 500) — kegagalan
     * kirim adalah hasil yang sah, bukan galat request (FR-012/FR-013).
     */
    public function resendNotification(Request $request, Preorder $preorder): JsonResponse
    {
        abort_unless($request->user()->isOwnerOrAdmin(), 403, __('preorders.not_authorized'));

        $notification = $this->notifier->notifyStatusChange($preorder, 'manual_resend');

        return response()->json([
            'status' => $notification->status,
            'recipient_email' => $notification->recipient_email,
            'sent_at' => $notification->sent_at?->toIso8601String(),
        ]);
    }

    private function presentNotification(?\App\Models\PreorderNotification $notification): ?array
    {
        if (! $notification) {
            return null;
        }

        return [
            'trigger' => $notification->trigger,
            'status' => $notification->status,
            'error_message' => $notification->error_message,
            'sent_at' => $notification->sent_at?->toIso8601String(),
        ];
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

        // 007-preorder-import-export-notify (US4) — SETELAH commit di
        // atas, tidak pernah bisa membuat respons ini gagal (research.md R7).
        $this->preorderService->notifyStatusChangeSafely($preorder);

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
