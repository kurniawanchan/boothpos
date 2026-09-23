<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Exports\GenericArrayExport;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\ActivityLogger;
use App\Services\CustomerExportImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class CustomerController extends Controller
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private CustomerExportImportService $exportImportService,
    ) {}

    /**
     * 020-customer-data-import-export — gerbangnya SAMA PERSIS dengan
     * bulk master-data lain (MasterDataExportController, ImportMasterDataRequest):
     * canAccessAnyMenu(['artists', 'categories', 'products', 'stock',
     * 'vendors', 'materials', 'roles', 'users']) — bukan isOwnerOrAdmin().
     * CATATAN: 'customers' SENGAJA tidak dimasukkan ke daftar menu_keys ini
     * — kalau dimasukkan, kasir (yang juga punya menu_keys 'customers'
     * untuk CRUD satu-per-satu) ikut lolos, padahal ekspor/impor massal
     * PII pelanggan harus tetap ketat di tier owner/admin/inventory saja
     * (satu-satunya tiga peran yang SEMUANYA memiliki seluruh menu_keys
     * pada daftar di atas — lihat migrasi 2026_10_09_000002). CLAUDE.md
     * menyebut ini "canManageMasterData()" sebagai nama konsep — method
     * itu TIDAK benar-benar ada di kode; mekanisme sungguhannya adalah
     * pengecekan menu_keys ini.
     */
    public function export(Request $request)
    {
        abort_unless($this->authorizedForBulkOperation($request), 403, __('customers.not_authorized'));

        return Excel::download(new GenericArrayExport($this->exportImportService->export()), 'customers.xlsx');
    }

    public function importTemplate(Request $request)
    {
        abort_unless($this->authorizedForBulkOperation($request), 403, __('customers.not_authorized'));

        return Excel::download(new GenericArrayExport($this->exportImportService->template()), 'template-customers.xlsx');
    }

    public function import(Request $request): JsonResponse
    {
        abort_unless($this->authorizedForBulkOperation($request), 403, __('customers.not_authorized'));

        $request->validate([
            'file' => Rule::file()->max(10240)->rules(['mimes:xlsx']),
        ]);

        $result = $this->exportImportService->import($request->file('file'), $request->boolean('dry_run'), $request->user());

        if (! $result['applied'] && ! $result['dry_run']) {
            return response()->json([
                'message' => __('customers.import_nothing_saved'),
                'row_errors' => $result['row_errors'],
            ], 409);
        }

        return response()->json([
            'dry_run' => $result['dry_run'],
            'created_count' => $result['created_count'],
            'updated_count' => $result['updated_count'],
            'row_errors' => $result['row_errors'],
        ], $result['dry_run'] ? 200 : 201);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);
        $perPage = min((int) $request->integer('per_page', 25), 100);

        $customers = Customer::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->string('search');
                $q->where(fn ($q2) => $q2->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('social_handle', 'like', "%{$term}%"));
            })
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => CustomerResource::collection($customers->items()),
            'meta' => [
                'current_page' => $customers->currentPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
                'last_page' => $customers->lastPage(),
            ],
        ]);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        return response()->json(new CustomerResource(Customer::create($request->validated())), 201);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer->update($request->validated());
        return response()->json(new CustomerResource($customer->fresh()));
    }

    /**
     * 009-ui-ux-refinements User Story 5 (T039) — riwayat transaksi
     * pelanggan, menggabungkan Order (penjualan langsung) dan Preorder
     * dalam satu daftar yang diurutkan berdasarkan tanggal terbaru.
     * Auth memakai tier baca yang sama dengan index() (viewAny) — bukan
     * tier hapus (delete) milik destroy() di atas, sesuai kontrak
     * api-deltas.md ("no widening").
     *
     * Order memiliki kolom status sendiri (enum completed/voided) —
     * dipakai apa adanya. Preorder memakai nilai kolom status miliknya
     * sendiri (ordered/dp_paid/arrived/settled/handed_over/cancelled).
     */
    public function transactions(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        // Scope DataModeScope global pada Order/Preorder sudah otomatis
        // menyaring sesuai mode DEMO/LIVE aktif — tidak perlu filter manual.
        $orders = $customer->orders()->get()->map(fn (\App\Models\Order $order) => [
            'type' => 'order',
            'id' => $order->id,
            'number' => $order->order_number,
            'status' => $order->status,
            'total_amount' => number_format((float) $order->total_amount, 2, '.', ''),
            'date' => $order->created_at?->toIso8601String(),
        ]);

        $preorders = $customer->preorders()->get()->map(fn (\App\Models\Preorder $preorder) => [
            'type' => 'preorder',
            'id' => $preorder->id,
            'number' => $preorder->preorder_number,
            'status' => $preorder->status,
            'total_amount' => number_format((float) $preorder->total_amount, 2, '.', ''),
            'date' => $preorder->created_at?->toIso8601String(),
        ]);

        $transactions = $orders->concat($preorders)
            ->sortByDesc('date')
            ->values();

        return response()->json(['data' => $transactions]);
    }

    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('delete', $customer);

        // Guard — pelanggan yang pernah punya order/pre-order (status apa
        // pun) tidak boleh dihapus, mengikuti pola guard hapus Artist/
        // Category (lihat plan 009-ui-ux-refinements R6). CATATAN: Order/
        // Preorder TIDAK memakai trait SoftDeletes (tidak ada kolom
        // deleted_at) — lihat catatan yang sama di EventController::destroy().
        $hasTransactions = $customer->orders()->exists() || $customer->preorders()->exists();

        if ($hasTransactions) {
            return response()->json([
                'message' => __('master_data.customer_delete_has_transactions'),
            ], 409);
        }

        // F13.4 — hapus data adalah tindakan sensitif; log ditulis DI DALAM
        // transaksi yang sama dengan delete-nya (atomik, ikut rollback
        // bersama). CATATAN PII: snapshot fillable Customer memuat
        // phone/email/social_handle — ini masuk activity log internal
        // (audit trail, bukan permukaan yang diekspor/terlihat artist),
        // konsisten dengan catatan PII di Customer model (L10-14) yang
        // membatasi ekspor/laporan ke artist, bukan log audit internal.
        DB::transaction(function () use ($customer, $request) {
            $snapshot = $customer->only($customer->getFillable());

            $customer->delete();

            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'deleted',
                entityType: 'Customer',
                entityId: $customer->id,
                description: "Menghapus pelanggan {$customer->name}.",
                oldValues: $snapshot,
            );
        });

        return response()->json(null, 204);
    }

    private function authorizedForBulkOperation(Request $request): bool
    {
        return $request->user()?->canAccessAnyMenu([
            'artists', 'categories', 'products', 'stock', 'vendors', 'materials', 'roles', 'users',
        ]) ?? false;
    }
}
