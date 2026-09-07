<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\InvoicePaymentSetting;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoiceService) {}

    /**
     * Daftar keseluruhan (FR-006), filterable via ?status=/?company_id=/
     * ?license_id= — menggantikan rute lama /companies/{company}/invoices
     * (019-billing-system, Invoice kini menu mandiri, bukan lagi anak
     * Company — research.md R2').
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $invoices = Invoice::query()
            // research.md R12 — company.businessType di-eager-load bersama
            // company/license: berbeda dari license/subtotal (snapshot),
            // business_type dibaca live karena itu fakta tentang Company
            // itu sendiri, bukan fakta pembayaran yang dibekukan saat
            // invoice dibuat.
            ->with(['license', 'company.businessType'])
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('license_id'), fn ($q) => $q->where('license_id', $request->integer('license_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => InvoiceResource::collection($invoices->items()),
            'meta' => [
                'current_page' => $invoices->currentPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
                'last_page' => $invoices->lastPage(),
            ],
        ]);
    }

    /**
     * FR-012 — dihitung langsung dari query yang sudah ter-scope
     * data_mode (Invoice::query() lewat global scope HasDataMode), sesuai
     * dengan konvensi kodebase ini: statistik tidak boleh sampai
     * disagree dengan apa yang tampil di layar yang sama.
     */
    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $unpaid = Invoice::query()->where('status', 'unpaid');
        $paid = Invoice::query()->where('status', 'paid');

        return response()->json([
            'unpaid' => [
                'count' => (clone $unpaid)->count(),
                'total' => number_format((float) (clone $unpaid)->sum('grand_total'), 2, '.', ''),
            ],
            'paid' => [
                'count' => (clone $paid)->count(),
                'total' => number_format((float) (clone $paid)->sum('grand_total'), 2, '.', ''),
            ],
            'overall_count' => Invoice::query()->count(),
        ]);
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $subtotal = (float) $data['subtotal'];
        $discount = (float) ($data['discount'] ?? 0);

        // BUG YANG DITEMUKAN & DIPERBAIKI (019-billing-system) —
        // terverifikasi lewat test sungguhan: mengandalkan default kolom
        // `status` di migrasi TIDAK membuat objek in-memory hasil
        // create() ikut punya nilai itu — Eloquent hanya tahu atribut
        // yang benar-benar dikirim di create(), bukan default level-DB,
        // sampai model di-refresh. 'status' di-set eksplisit di sini
        // (FR-002), konsisten dengan pola OrderService/PurchaseOrderService
        // yang juga tidak pernah mengandalkan default skema untuk status.
        // FR-015 — payment_information invoice baru default ke Settings →
        // Pembayaran (InvoicePaymentSetting singleton) bila field ini
        // dikosongkan di form; tetap bisa di-override per invoice.
        $paymentInformation = $data['payment_information'] ?? null;
        if (blank($paymentInformation)) {
            $setting = InvoicePaymentSetting::query()->find(1);
            if ($setting && ! blank($setting->instructions)) {
                $paymentInformation = $setting->instructions;
            }
        }

        $invoice = Invoice::create([
            'invoice_number' => $this->invoiceService->generateNumber(),
            'company_id' => $data['company_id'],
            'license_id' => $data['license_id'],
            'subtotal' => $subtotal,
            'discount' => $discount,
            'grand_total' => $subtotal - $discount,
            'due_date' => $data['due_date'],
            'payment_information' => $paymentInformation,
            'notes' => $data['notes'] ?? null,
            'status' => 'unpaid',
        ]);

        return response()->json(new InvoiceResource($invoice->load(['license', 'company.businessType'])), 201);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);

        return response()->json(new InvoiceResource($invoice->load(['license', 'company.businessType'])));
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        try {
            $invoice = $this->invoiceService->update($invoice, $request->validated());
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 409);
        }

        return response()->json(new InvoiceResource($invoice->load(['license', 'company.businessType'])));
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        $this->authorize('delete', $invoice);

        try {
            $this->invoiceService->delete($invoice);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 409);
        }

        return response()->json(null, 204);
    }

    public function markPaid(Invoice $invoice): JsonResponse
    {
        $this->authorize('markPaid', $invoice);

        try {
            $invoice = $this->invoiceService->markPaid($invoice);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 409);
        }

        return response()->json(new InvoiceResource($invoice));
    }

    public function cancel(Invoice $invoice): JsonResponse
    {
        $this->authorize('cancel', $invoice);

        try {
            $invoice = $this->invoiceService->cancel($invoice);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 409);
        }

        return response()->json(new InvoiceResource($invoice));
    }
}
