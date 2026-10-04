<?php

namespace App\Http\Controllers\Api;

use App\Exports\GenericArrayExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\DuplicatePreordersRequest;
use App\Http\Requests\SplitPreorderRequest;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UpdatePaymentConfirmationRequest;
use App\Http\Requests\StorePreorderRequest;
use App\Http\Requests\UpdatePreorderRequest;
use App\Http\Resources\CustomerResource;
use App\Mail\PreorderInvoiceMail;
use App\Models\Payment;
use App\Models\Preorder;
use App\Models\PreorderNotification;
use App\Services\ImageUploadService;
use App\Services\PreorderExportImportService;
use App\Services\PreorderNotifier;
use App\Services\PreorderService;
use App\Support\BuildsInvoiceDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class PreorderController extends Controller
{
    use BuildsInvoiceDocument;

    public function __construct(
        private PreorderService $preorderService,
        private PreorderExportImportService $exportImportService,
        private PreorderNotifier $notifier,
        private ImageUploadService $imageUploadService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 25), 100);

        // 013-preorder-list-filters-receipt (T005) — eager-load items.artist
        // supaya sellersFor() tidak lazy-load per baris di list yang
        // dipaginasi (risiko N+1).
        // 027-preorder-duplicate-split — withExists: satu subquery EXISTS untuk
        // seluruh halaman, bukan satu query payments per baris (N+1). Dipakai
        // frontend untuk menonaktifkan aksi Split, karena pembayaran apa pun
        // (bahkan yang bernilai kecil) menghalangi pemisahan.
        $query = Preorder::query()->with(['customer', 'items.artist'])->withExists('payments');
        $query = $this->applyFilters($query, $request);
        $query = $this->applySort($query, $request);

        $preorders = $query->paginate($perPage);

        $data = collect($preorders->items())->map(fn (Preorder $p) => [
            'id' => $p->id, 'preorder_number' => $p->preorder_number,
            'customer_id' => $p->customer_id, 'customer_name' => $p->customer->name, 'status' => $p->status,
            'dispatch_status' => $p->dispatch_status,
            'invoice_sent_at' => $p->invoice_sent_at?->toIso8601String(),
            'shipping_at' => $p->shipping_at?->toIso8601String(),
            'updated_at' => $p->updated_at,
            'fulfillment' => $p->fulfillment,
            'total_amount' => number_format((float) $p->total_amount, 2, '.', ''),
            'paid_amount' => number_format((float) $p->paid_amount, 2, '.', ''),
            'has_payments' => (bool) $p->payments_exists,
            'outstanding' => number_format($p->outstanding(), 2, '.', ''),
            'created_at' => $p->created_at,
            'sellers' => $this->sellersFor($p),
            // Requested: flag a Mail Order pre-order in the list that's
            // missing shipping cost or a customer address — both are only
            // meaningful for fulfillment=courier, so the frontend gates the
            // flag on that itself rather than this being computed here
            // (keeps the same field usable regardless of fulfillment).
            'shipping_cost' => number_format((float) $p->shipping_cost, 2, '.', ''),
            'customer_has_address' => filled($p->customer->address),
            // Alamat pelanggan sendiri (bukan hanya ada/tidaknya) supaya popup
            // bendera "ongkir/alamat belum diisi" bisa langsung menampilkannya —
            // pelanggan sudah dimuat untuk baris ini, jadi tanpa query tambahan.
            'customer_address' => $p->customer->address,
            // Requested: clicking the flag above shows shipping cost +
            // notes — staff sometimes write the customer's actual address
            // into free-text notes as a workaround when it was never
            // captured on the Customer record itself, so this is the
            // fastest way to check "is the info really missing, or just
            // not on the customer record".
            'notes' => $p->notes,
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

    /**
     * 027-preorder-duplicate-split (US1/US2, research.md Decision 3) —
     * SELALU 200 dengan laporan per-pre-order, mengikuti bulkEmailInvoices():
     * tiap salinan memakai transaksinya sendiri, jadi satu pre-order yang
     * gagal (barang sudah dihapus, diskon melebihi total baru, id milik mode
     * lain) tidak menggagalkan sisanya. Tidak ada email yang dikirim.
     */
    public function duplicate(DuplicatePreordersRequest $request): JsonResponse
    {
        $ids = array_values(array_unique(array_map('intval', $request->validated()['preorder_ids'])));

        // Query ber-scope mode: id milik mode lain tak ikut terambil dan
        // dilaporkan "tidak ditemukan" di bawah, bukan diduplikasi.
        $sources = Preorder::with('items')->whereIn('id', $ids)->get()->keyBy('id');

        $results = [];
        foreach ($ids as $id) {
            $source = $sources->get($id);

            if (! $source) {
                $results[] = ['source_id' => $id, 'source_number' => null, 'status' => 'failed', 'error' => __('preorders.duplicate_not_found')];
                continue;
            }

            try {
                $copy = $this->preorderService->duplicate($source, $request->user());
                $results[] = [
                    'source_id' => $source->id, 'source_number' => $source->preorder_number,
                    'status' => 'created', 'preorder' => $this->present($copy),
                ];
            } catch (ValidationException $e) {
                $results[] = [
                    'source_id' => $source->id, 'source_number' => $source->preorder_number,
                    'status' => 'failed', 'error' => (string) collect($e->errors())->flatten()->first(),
                ];
            } catch (ModelNotFoundException) {
                // Pelanggan/varian terhapus di sela-sela pengecekan dan penyimpanan.
                $results[] = [
                    'source_id' => $source->id, 'source_number' => $source->preorder_number,
                    'status' => 'failed', 'error' => __('preorders.duplicate_not_found'),
                ];
            }
        }

        return response()->json(['data' => $results]);
    }

    /**
     * 027-preorder-duplicate-split (US3/US4, research.md Decisions 4–5) —
     * semua aturan ada di PreorderService; di sini hanya memetakan
     * ValidationException ke kode statusnya. Service sendiri yang menandai
     * konflik bisnis dengan ->status(409) (status/pembayaran/diskon), sisanya
     * tetap 422 (salah bentuk/nilai) sesuai konvensi API di CLAUDE.md.
     */
    public function split(SplitPreorderRequest $request, Preorder $preorder): JsonResponse
    {
        try {
            $validated = $request->validated();
            $result = $validated['mode'] === 'by_seller'
                ? $this->preorderService->splitBySeller($preorder, $request->user())
                : $this->preorderService->split($preorder, $validated['items'], $request->user());
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status);
        }

        return response()->json([
            'original' => $this->present($result['original']),
            'created' => array_map(fn (Preorder $p) => $this->present($p), $result['created']),
        ], 201);
    }

    public function show(Preorder $preorder): JsonResponse
    {
        $preorder->load(['items.variant.product.category', 'payments.proofs', 'payments.recorder', 'shipment', 'customer', 'notifications', 'splitChildren']);

        return response()->json([
            ...$this->present($preorder),
            // 007-preorder-import-export-notify (US4) — biar layar detail
            // yang sudah ada bisa menampilkan status notifikasi terakhir
            // tanpa request tambahan (data-model.md).
            'latest_notification' => $this->presentNotification($preorder->latestNotification()),
        ]);
    }

    /**
     * 022-preorder-invoice-crud-overhaul (US1, FR-001/FR-001a/FR-002) —
     * status guard & delta stok sepenuhnya ada di PreorderService::update();
     * di sini hanya memetakan ValidationException-nya jadi 409 (konflik
     * aturan bisnis, bukan 422 shape/validasi form — CLAUDE.md API
     * conventions).
     */
    public function update(UpdatePreorderRequest $request, Preorder $preorder): JsonResponse
    {
        try {
            $preorder = $this->preorderService->update($preorder, $request->validated(), $request->user());
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 409);
        }

        return response()->json($this->present($preorder));
    }

    /**
     * 022-preorder-invoice-crud-overhaul (US1, FR-003/FR-004) — 409 kalau
     * status bukan "ordered" atau sudah ada pembayaran; pesan mengarahkan
     * ke aksi "Cancel" yang sudah ada.
     */
    public function destroy(Preorder $preorder): JsonResponse
    {
        try {
            $this->preorderService->delete($preorder);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 409);
        }

        return response()->json(null, 204);
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
        $preorder->load(['items', 'payments.proofs', 'payments.recorder', 'customer', 'event']);

        return response()->json($this->invoicePayload($preorder));
    }

    /**
     * 022-preorder-invoice-crud-overhaul (US5, FR-013, research.md
     * Decision 5) — mengembalikan payload invoice LENGKAP (sama persis
     * dengan invoice()) untuk setiap id yang diminta, dalam SATU respons —
     * supaya frontend bisa merender+zip banyak PDF sekaligus di klien
     * tanpa N request berurutan, tanpa pernah membuat PDF di server.
     */
    public function bulkInvoices(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preorder_ids' => ['required', 'array', 'min:1'],
            'preorder_ids.*' => ['integer', 'exists:preorders,id'],
            'document' => ['required', 'in:invoice,payment_invoice'],
        ]);

        $preorders = Preorder::with(['items', 'payments.proofs', 'payments.recorder', 'customer', 'event'])
            ->whereIn('id', $validated['preorder_ids'])
            ->get();

        $data = $preorders->map(fn (Preorder $p) => $this->invoicePayload($p))->all();

        return response()->json(['data' => $data]);
    }

    /**
     * 022-preorder-invoice-crud-overhaul (US5, FR-014/FR-015) — satu email
     * per pre-order terpilih, memakai PreorderInvoiceMail (badan HTML,
     * TANPA lampiran PDF — research.md Decision 5), mengikuti pola
     * catat-setiap-percobaan PreorderNotifier yang sudah ada (trigger baru
     * `bulk_invoice_email`, bukan bentuk log baru). SELALU 200 dengan
     * laporan per-baris — satu pelanggan tanpa email tidak boleh
     * menggagalkan seluruh batch (FR-015).
     */
    public function bulkEmailInvoices(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preorder_ids' => ['required', 'array', 'min:1'],
            'preorder_ids.*' => ['integer', 'exists:preorders,id'],
            'document' => ['required', 'in:invoice,payment_invoice'],
        ]);

        $preorders = Preorder::with(['items', 'customer', 'payments.proofs', 'payments.recorder'])
            ->whereIn('id', $validated['preorder_ids'])
            ->get();

        $results = $preorders->map(function (Preorder $preorder) use ($validated) {
            $email = $preorder->customer?->email;
            $payment = $validated['document'] === 'payment_invoice' ? $preorder->payments->last() : null;

            $status = $this->sendBulkInvoiceEmail($preorder, $validated['document'], $email, $payment);

            return ['preorder_id' => $preorder->id, 'status' => $status];
        });

        return response()->json(['data' => $results]);
    }

    private function sendBulkInvoiceEmail(Preorder $preorder, string $document, ?string $email, $payment): string
    {
        if (empty($email)) {
            $status = 'skipped_no_email';
        } elseif (config('mail.default') === 'log') {
            $status = 'skipped_not_configured';
        } else {
            try {
                Mail::to($email)->send(new PreorderInvoiceMail($preorder, $document, $payment));
                $status = 'sent';
            } catch (\Throwable $e) {
                $status = 'failed';
            }
        }

        PreorderNotification::create([
            'preorder_id' => $preorder->id,
            'trigger' => 'bulk_invoice_email',
            'document_type' => $document,
            'recipient_email' => $email,
            'status' => $status,
            'sent_at' => now(),
        ]);

        return $status;
    }

    private function invoicePayload(Preorder $preorder): array
    {
        return [
            ...$this->present($preorder),
            'document_type' => \App\Support\PreorderDocumentType::forStatus($preorder->status),
            // 014-sales-receipt-event-footer (US2, R2) — event_id preorder
            // opsional (beda dengan Order yang selalu punya event), jadi
            // semua field ini null-safe lewat ?->.
            'event_name' => $preorder->event?->name,
            'event_location' => $preorder->event?->location,
            'event_start_date' => $preorder->event?->start_date?->toDateString(),
            'event_end_date' => $preorder->event?->end_date?->toDateString(),
            // 023-event-availability-invoice-redesign (US2) — diselesaikan
            // ke tanggal asli lewat Event::availableOnDate(), satu-satunya
            // tempat pemetaan 'day_1'/'day_2' -> tanggal terjadi.
            'event_available_on_date' => $preorder->event?->availableOnDate()?->toDateString(),
            // Sama seperti OrderController::receipt() — pilihan mentah
            // dikirim juga supaya frontend bisa membedakan 'both' (tampil
            // sebagai rentang) dari tidak ada batasan (keduanya membuat
            // availableOnDate() null).
            'event_available_on' => $preorder->event?->available_on,
            // 022-preorder-invoice-crud-overhaul (US3, research.md Decision 1/2)
            ...$this->buildInvoiceDocumentFields($this->imageUploadService),
        ];
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
            'status', 'event_id', 'customer_id', 'fulfillment', 'dispatch_status', 'search', 'date_from', 'date_to',
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

    /**
     * 013-preorder-list-filters-receipt (T022, FR-010-013) — statistik
     * agregat atas himpunan preorder yang SAMA yang sedang difilter di
     * index(), lewat helper applyFilters() yang sama (research.md R4) —
     * supaya list dan ringkasan tidak pernah berbeda soal "apa yang
     * sedang difilter". Pakai total_amount/paid_amount milik Preorder
     * langsung (bukan rekomputasi live dari payments) karena keduanya
     * sudah otoritatif di level preorder (research.md R3, beda dengan
     * kebutuhan proration per-artist di ReportController).
     */
    public function summary(Request $request): JsonResponse
    {
        $query = Preorder::query();
        $query = $this->applyFilters($query, $request);

        $transactionCount = (clone $query)->count();

        $groupedByStatus = (clone $query)
            ->selectRaw('status, COUNT(*) as cnt, SUM(total_amount) as total')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $statuses = ['ordered', 'dp_paid', 'arrived', 'settled', 'handed_over', 'cancelled'];

        // 013-preorder-list-filters-receipt (US5, klarifikasi pengguna) —
        // "total untuk setiap status" dimaksudkan sebagai JUMLAH (qty)
        // transaksi per status, bukan cuma nilai uangnya — jadi tiap
        // status membawa `count` di samping `total_amount`, bukan salah
        // satunya saja.
        $byStatus = array_map(fn ($status) => [
            'status' => $status,
            'count' => (int) ($groupedByStatus[$status]->cnt ?? 0),
            'total_amount' => number_format((float) ($groupedByStatus[$status]->total ?? 0), 2, '.', ''),
        ], $statuses);

        // BUG YANG DITEMUKAN & DIPERBAIKI — sebelumnya menjumlahkan SEMUA
        // preorder termasuk yang sudah "Cancelled", jadi Grand Total/Sisa
        // tagihan tetap menghitung uang preorder yang batal seolah masih
        // berlaku. `by_status` di atas TETAP menampilkan baris Cancelled-
        // nya sendiri (diagnostik per status, disengaja), tapi angka
        // ringkasan uang keseluruhan harus mengecualikannya — sama seperti
        // precedent yang sudah ada di breakdown per-seller
        // (preordersByArtist(): "preorder cancelled tidak masuk total
        // uang", 012-seller-preorder-report-detail-export).
        // transaction_count TETAP menghitung semua status (termasuk
        // Cancelled) karena itu jumlah baris yang tampil di tabel, bukan
        // angka uang.
        $grandTotal = (float) (clone $query)->where('status', '!=', 'cancelled')->sum('total_amount');
        $totalPaid = (float) (clone $query)->where('status', '!=', 'cancelled')->sum('paid_amount');

        return response()->json([
            'transaction_count' => $transactionCount,
            'by_status' => $byStatus,
            'grand_total' => number_format($grandTotal, 2, '.', ''),
            'total_outstanding' => number_format($grandTotal - $totalPaid, 2, '.', ''),
        ]);
    }

    /**
     * 013-preorder-list-filters-receipt (T003) — hasil ekstraksi rantai
     * filter index() apa adanya (perilaku identik), ditambah filter
     * artist_id baru. Pakai whereHas (WHERE EXISTS), BUKAN join, supaya
     * satu Preorder dengan beberapa item dari artist yang sama tidak
     * pernah terduplikasi (research.md R2).
     */
    private function applyFilters(Builder $query, Request $request): Builder
    {
        return $query
            // Array-capable, same convention as ProductController::index()'s
            // artist_id/category_id (005-ux-enhancements-dashboard US1):
            // $request->array() normalizes both the new `status[]=a&status[]=b`
            // (multi-select filter, "can filter multiple / combine
            // conditions") and the old scalar `status=a` into an array, so
            // this is backward compatible with any existing caller.
            // array_filter drops empty strings so an empty-but-present param
            // doesn't turn whereIn([]) into "match nothing".
            ->when(count(array_filter($request->array('status'))) > 0, fn ($q) => $q->whereIn('status', array_filter($request->array('status'))))
            // Penanda manual invoice-terkirim/pengiriman-berjalan — pola
            // array yang sama dengan status/fulfillment di atas, sehingga
            // list, summary, dan export ikut terfilter tanpa kode tambahan.
            ->when(count(array_filter($request->array('dispatch_status'))) > 0, fn ($q) => $q->whereIn('dispatch_status', array_filter($request->array('dispatch_status'))))
            ->when($request->filled('event_id'), fn ($q) => $q->where('event_id', $request->integer('event_id')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when(count(array_filter($request->array('fulfillment'))) > 0, fn ($q) => $q->whereIn('fulfillment', array_filter($request->array('fulfillment'))))
            // 007-preorder-import-export-notify (US1) — parsial, tidak peka
            // huruf besar/kecil, terhadap nama pelanggan (research.md R1).
            // Diperluas (permintaan produk owner berikutnya) supaya juga
            // cocok terhadap nomor pre-order itu sendiri dan nama/SKU item
            // DI DALAM pre-order tsb — satu kotak pencarian, tiga sumber,
            // bukan tiga kotak terpisah.
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->string('search')->value();
                // Requested: also match the outstanding balance — a cashier
                // often only remembers "the one still owing around 135rb",
                // not the PO number or customer name. `outstanding` isn't a
                // real column (Preorder::outstanding() computes it in PHP),
                // so it's matched here via the same total_amount-minus-
                // paid_amount expression, cast to text so a LIKE substring
                // search works the same way it does for the other fields.
                // Digits-only, so typing with Rupiah punctuation ("135.000",
                // "Rp 135.000") still matches — the stored amount itself
                // never contains separators once cast to text.
                $digitsOnly = preg_replace('/\D+/', '', $term);
                $q->where(function ($qq) use ($term, $digitsOnly) {
                    $qq->where('preorder_number', 'like', "%{$term}%")
                        ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$term}%"))
                        ->orWhereHas('items', fn ($iq) => $iq->where('name_snapshot', 'like', "%{$term}%")
                            ->orWhere('sku_snapshot', 'like', "%{$term}%"));

                    if ($digitsOnly !== '') {
                        $qq->orWhereRaw('CAST((total_amount - paid_amount) AS CHAR) LIKE ?', ["%{$digitsOnly}%"]);
                    }
                });
            })
            ->when(count(array_filter($request->array('artist_id'))) > 0, fn ($q) => $q->whereHas(
                'items',
                fn ($iq) => $iq->whereIn('artist_id', array_filter($request->array('artist_id')))
            ));
    }

    /**
     * Sortable columns for the list screen — a fixed whitelist, never the
     * raw `sort_by` string, so a request can't order by an arbitrary
     * column/expression. `customer_name` needs a real JOIN (not
     * whereHas/subquery) to be orderable; `preorders.*` is selected
     * explicitly in that branch so the join's own `customers.id` never
     * collides with `preorders.id` in the paginated result. `outstanding`
     * isn't a real column (Preorder::outstanding() computes it in PHP), so
     * it's sorted via the same total_amount-minus-paid_amount expression
     * that method uses. `sellers` has no single sortable value (a preorder
     * can have several) and is deliberately left out — its column header
     * simply renders without a sort affordance.
     */
    private function applySort(Builder $query, Request $request): Builder
    {
        $sortBy = $request->string('sort_by')->value();
        $sortDir = strtolower($request->string('sort_dir', 'desc')->value()) === 'asc' ? 'asc' : 'desc';

        $columns = [
            'preorder_number' => 'preorders.preorder_number',
            'customer_name' => 'customers.name',
            'status' => 'preorders.status',
            'fulfillment' => 'preorders.fulfillment',
            'total_amount' => 'preorders.total_amount',
            'created_at' => 'preorders.created_at',
            'updated_at' => 'preorders.updated_at',
        ];

        if ($sortBy === 'customer_name') {
            return $query->join('customers', 'customers.id', '=', 'preorders.customer_id')
                ->select('preorders.*')
                ->orderBy($columns['customer_name'], $sortDir);
        }

        if ($sortBy === 'outstanding') {
            return $query->orderByRaw("(preorders.total_amount - preorders.paid_amount) {$sortDir}");
        }

        if (isset($columns[$sortBy])) {
            return $query->orderBy($columns[$sortBy], $sortDir);
        }

        return $query->orderByDesc('preorders.created_at');
    }

    /**
     * 013-preorder-list-filters-receipt (T004) — daftar penjual unik
     * (id, name) lintas item preorder, urut kemunculan pertama. Null-safe:
     * item dengan artist_id yang tidak resolve (referensi menggantung)
     * dilewati, tidak crash.
     */
    private function sellersFor(Preorder $preorder): array
    {
        $sellers = [];

        foreach ($preorder->items as $item) {
            $artist = $item->artist;

            if (! $artist || isset($sellers[$artist->id])) {
                continue;
            }

            $sellers[$artist->id] = ['id' => $artist->id, 'name' => $artist->name];
        }

        return array_values($sellers);
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

    /**
     * Ubah manual penanda "invoice terkirim" / "pengiriman berjalan".
     *
     * Bisa maju MAUPUN mundur (salah klik harus bisa dikoreksi), dan tidak
     * terikat urutan `status` utama — tidak menyentuh stok, pembayaran, atau
     * notifikasi email. Satu-satunya guard: pre-order yang sudah dibatalkan
     * tidak lagi punya invoice/pengiriman aktif (409, konvensi konflik bisnis).
     */
    public function updateDispatchStatus(Request $request, Preorder $preorder): JsonResponse
    {
        $validated = $request->validate([
            'dispatch_status' => ['required', 'in:'.implode(',', Preorder::DISPATCH_STATUSES)],
        ]);

        if ($preorder->status === 'cancelled') {
            return response()->json([
                'message' => __('preorders.dispatch_status_cancelled'),
                'errors' => ['dispatch_status' => [__('preorders.dispatch_status_cancelled')]],
            ], 409);
        }

        // Pengiriman kurir hanya ada untuk Mail Order (fulfillment=courier);
        // pesanan pickup diambil di booth, jadi "shipping" tak pernah berlaku.
        if ($validated['dispatch_status'] === 'shipping' && $preorder->fulfillment !== 'courier') {
            return response()->json([
                'message' => __('preorders.dispatch_status_shipping_mail_order_only'),
                'errors' => ['dispatch_status' => [__('preorders.dispatch_status_shipping_mail_order_only')]],
            ], 409);
        }

        // Tanggal mengikuti nilai TUJUAN, bukan riwayat klik: menandai ulang
        // yang sudah aktif tidak menggeser tanggalnya (`?? now()`), mundur
        // dari "shipping" menghapus tanggal pengiriman tapi menyimpan tanggal
        // invoice, dan kembali ke "pending" menghapus keduanya.
        $target = $validated['dispatch_status'];
        $preorder->update([
            'dispatch_status' => $target,
            'invoice_sent_at' => $target === 'pending' ? null : ($preorder->invoice_sent_at ?? ($target === 'invoice_sent' ? now() : null)),
            'shipping_at' => $target === 'shipping' ? ($preorder->shipping_at ?? now()) : null,
        ]);

        return response()->json($this->present(
            // Eager-load sama persis dengan show() — present() menyembunyikan
            // relasi yang tak dimuat secara diam-diam (lihat CLAUDE.md).
            $preorder->fresh(['items.variant.product.category', 'payments.proofs', 'payments.recorder', 'shipment', 'customer'])
        ));
    }

    /**
     * Hapus satu pembayaran pre-order (+ bukti bayarnya) dan hitung ulang
     * status. Hanya owner/admin: ini menghapus catatan uang, jadi kasir tidak
     * boleh menyembunyikan pemasukan lewat sini. Konflik status → 409.
     */
    public function destroyPayment(Request $request, Preorder $preorder, Payment $payment): JsonResponse
    {
        abort_unless($request->user()->isOwnerOrAdmin(), 403, __('preorders.not_authorized'));

        try {
            $preorder = $this->preorderService->deletePayment($preorder, $payment, $request->user());
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status);
        }

        return response()->json($this->present($preorder));
    }

    /**
     * 031-optional-payment-proof — tambah / ubah / ganti konfirmasi (bukti, referensi,
     * catatan) satu pembayaran non-tunai pre-order; kembaran OrderController::
     * updatePaymentConfirmation(). Hanya owner/admin atau PENCATAT pembayaran itu (403),
     * dijaga di sini dan diulang di PaymentService; sisanya (pre-order batal 409, tunai 422,
     * hasil tak boleh kosong 422) ada di service. Pre-order yang sudah diserahkan tetap boleh:
     * konfirmasi tak menggerakkan uang.
     */
    public function updatePaymentConfirmation(UpdatePaymentConfirmationRequest $request, Preorder $preorder, Payment $payment): JsonResponse
    {
        abort_unless($payment->mayManageConfirmation($request->user()), 403, __('orders_payments.payment_confirmation_not_allowed'));

        try {
            $preorder = $this->preorderService->updatePaymentConfirmation($preorder, $payment, $request->validated(), $request->user());
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status);
        }

        return response()->json($this->present($preorder));
    }

    public function storePayment(StorePaymentRequest $request, Preorder $preorder): JsonResponse
    {
        try {
            $preorder = $this->preorderService->recordPayment($preorder, $request->validated(), $request->user(), $replayed);
        } catch (ValidationException $e) {
            // Service menandai konflik status dengan ->status(409); selebihnya 422.
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status);
        }

        // 200 = replay `client_ref` (pembayarannya sudah tercatat, tak ada baris baru);
        // 201 = pembayaran baru.
        return response()->json($this->present($preorder), $replayed ? 200 : 201);
    }

    private function present(Preorder $preorder): array
    {
        return [
            'id' => $preorder->id, 'preorder_number' => $preorder->preorder_number,
            'event_id' => $preorder->event_id, 'status' => $preorder->status,
            'dispatch_status' => $preorder->dispatch_status,
            'invoice_sent_at' => $preorder->invoice_sent_at?->toIso8601String(),
            'shipping_at' => $preorder->shipping_at?->toIso8601String(),
            'updated_at' => $preorder->updated_at?->toIso8601String(),
            'fulfillment' => $preorder->fulfillment,
            // 024-invoice-layout-shipping-slip — sebelumnya hanya ada di
            // index(), tak pernah ikut present() padahal invoicePayload()
            // meng-spread present() (research.md Decision 1).
            'created_at' => $preorder->created_at?->toIso8601String(),
            // BUG YANG DITEMUKAN & DIPERBAIKI — surat jalan di panel detail
            // (PreordersView.vue, dimuat lewat show()/present(), BUKAN
            // invoice()/invoicePayload()) menampilkan blok "Dari" kosong
            // karena store_identity sebelumnya hanya ditambahkan oleh
            // invoicePayload() lewat BuildsInvoiceDocument, tidak pernah
            // ikut present(). Sekarang jadi field bersama di present()
            // (Constitution I) — invoicePayload() masih memanggil
            // buildInvoiceDocumentFields() sesudahnya juga, nilainya sama
            // persis, jadi tidak ada perbedaan perilaku di sana.
            'store_identity' => $this->buildStoreIdentity($this->imageUploadService),
            'subtotal' => number_format((float) $preorder->subtotal, 2, '.', ''),
            'shipping_cost' => number_format((float) $preorder->shipping_cost, 2, '.', ''),
            // 021-preorder-form-updates — nominal Rupiah tetap, sudah
            // dihitung ke dalam total_amount saat create() (tidak pernah
            // dihitung ulang di sini), tapi ditampilkan terpisah supaya
            // invoice/detail bisa menunjukkan "subtotal - diskon = total".
            'discount' => number_format((float) $preorder->discount, 2, '.', ''),
            'total_amount' => number_format((float) $preorder->total_amount, 2, '.', ''),
            'paid_amount' => number_format((float) $preorder->paid_amount, 2, '.', ''),
            'outstanding' => number_format($preorder->outstanding(), 2, '.', ''),
            // 028-partial-split-payment — ringkasan turunan dari entri pembayaran
            // (grand total / total terbayar / sisa / status / jumlah entri).
            'payment_summary' => $preorder->paymentSummary(),
            'expected_date' => $preorder->expected_date?->toDateString(),
            // 021-preorder-form-updates — tanggal ASLI (bukan label "Day 1"),
            // diturunkan dari rentang tanggal event saat create() (research.md
            // Decision 2); null bila fulfillment=courier atau tak ada event.
            'pickup_day' => $preorder->pickup_day?->toDateString(),
            // 021-preorder-form-updates — nilai DEFAULT/preferensi yang
            // dipilih saat preorder dibuat, BUKAN shipments.courier_name
            // (yang tetap kolom terpisah, wajib diisi ulang saat shipment
            // sungguhan dibuat — research.md Decision 1).
            'courier_name' => $preorder->courier_name,
            'cancel_reason' => $preorder->cancel_reason,
            // Catatan pesanan — sebelumnya hanya ada di baris list (index()),
            // padahal panel detail memuat dari show()/present() sehingga
            // `notes` selalu kosong di sana.
            'notes' => $preorder->notes,
            // 027-preorder-duplicate-split — asal pre-order (null untuk yang
            // dibuat biasa). Nomor diambil dari snapshot, jadi tetap tampil
            // meski pre-order sumbernya sudah dihapus (preorder_id jadi null).
            'source' => $preorder->source_type ? [
                'type' => $preorder->source_type,
                'preorder_id' => $preorder->source_preorder_id,
                'preorder_number' => $preorder->source_preorder_number,
            ] : null,
            // Hanya terisi bila relasinya di-eager-load (show() dan respons
            // split) — present() menyembunyikan relasi yang tak dimuat secara
            // diam-diam, lihat CLAUDE.md.
            'split_children' => $preorder->relationLoaded('splitChildren')
                ? $preorder->splitChildren->map(fn ($c) => ['id' => $c->id, 'preorder_number' => $c->preorder_number])->all()
                : [],
            // 013-preorder-list-filters-receipt (T004) — daftar penjual unik
            // yang muncul di preorder ini, dipakai frontend untuk kolom
            // seller di list & tampilan invoice per-item.
            'sellers' => $this->sellersFor($preorder),
            'items' => $preorder->relationLoaded('items') ? $preorder->items->map(fn ($i) => [
                'id' => $i->id, 'variant_id' => $i->variant_id, 'sku_snapshot' => $i->sku_snapshot,
                'name_snapshot' => $i->name_snapshot, 'qty' => $i->qty,
                'sell_price' => number_format((float) $i->sell_price, 2, '.', ''),
                'line_total' => number_format((float) $i->line_total, 2, '.', ''),
                'artist_id' => $i->artist_id, 'artist_name' => $i->artist?->name,
                // Added at the product owner's explicit request — the
                // variant this line snapshot came from may since have been
                // updated with its own image; falls back to null (not the
                // product's own image) since a deleted/changed variant has
                // no product to fall back to here, unlike lookupVariants().
                'image_url' => $i->relationLoaded('variant') ? $i->variant?->image_url : null,
                // Added at the product owner's explicit request, same
                // relation-guard convention as image_url just above — a
                // variant/product referenced here may since have been
                // deleted or reassigned to a different category.
                'category_name' => $i->relationLoaded('variant') ? $i->variant?->product?->category?->name : null,
            ]) : [],
            // Sebelumnya hilang total dari present() meski show() sudah
            // meng-eager-load ketiganya (dan openapi-pos-mvp.yaml sudah lama
            // mendokumentasikan field ini) — layar detail preorder di
            // frontend tidak pernah bisa menampilkan riwayat pembayaran atau
            // data pengiriman. Ditemukan lewat verifikasi browser sungguhan
            // saat integrasi frontend, bukan lewat test yang sudah ada.
            'customer' => $preorder->relationLoaded('customer') && $preorder->customer
                ? new CustomerResource($preorder->customer) : null,
            'payments' => $preorder->relationLoaded('payments') ? $preorder->payments->map(function ($p) use ($preorder) {
                $user = request()->user();
                $row = [
                    'id' => $p->id, 'method' => $p->method, 'purpose' => $p->purpose,
                    'amount' => number_format((float) $p->amount, 2, '.', ''),
                    'verification' => $p->verification, 'paid_at' => $p->paid_at,
                    // 028-partial-split-payment — jejak buku besar: nomor referensi,
                    // pencatat (null untuk baris lama), dan status entri. Entri
                    // `rejected` tidak dihitung ke total terbayar (PaymentSummary);
                    // selain itu semuanya "paid" (research Decision 9).
                    'reference' => $p->reference,
                    // 031 — catatan pembayaran; sebelumnya tersimpan tetapi tak pernah dikirim.
                    'notes' => $p->notes,
                    'recorded_by_name' => $p->relationLoaded('recorder') ? $p->recorder?->name : null,
                    'status' => $p->verification === 'rejected' ? 'rejected' : 'paid',
                    // 031 — dihitung SERVER per pembayaran (SPA tak menebak dari peran);
                    // pre-order batal tak bisa diubah, yang sudah diserahkan tetap boleh.
                    'can_edit_confirmation' => $user !== null && $preorder->status !== 'cancelled' && $p->confirmationEditableBy($user),
                ];

                // 024-invoice-layout-shipping-slip (US-payment-proof) — bukti pembayaran
                // diunggah SEBELUM payment dibuat lalu ditautkan lewat proof_token
                // (PaymentRecorder). File-nya sendiri TIDAK pernah dikirim langsung di sini
                // (disk privat) — frontend mengambilnya lewat endpoint otorisasi
                // /payment-proofs/{id}/file. 031: `proof_id` = bukti yang BERLAKU (yang lama
                // ditandai superseded_at, bukan dihapus), plus flag has_proof/can_view_proof;
                // dihilangkan (bukan diisi "tak ada bukti") bila relasi `proofs` tak dimuat.
                if ($p->relationLoaded('proofs')) {
                    $current = $p->currentProof();
                    $row['proof_id'] = $current?->id;
                    $row['has_proof'] = $current !== null;
                    $row['can_view_proof'] = $user !== null && $current !== null && $p->proofViewableBy($user, $current);
                }

                return $row;
            })->all() : [],
            'shipment' => $preorder->relationLoaded('shipment') && $preorder->shipment ? [
                'id' => $preorder->shipment->id,
                'courier_name' => $preorder->shipment->courier_name,
                'tracking_number' => $preorder->shipment->tracking_number,
                'shipping_cost' => number_format((float) $preorder->shipment->shipping_cost, 2, '.', ''),
                'recipient_name' => $preorder->shipment->recipient_name,
                'recipient_phone' => $preorder->shipment->recipient_phone,
                'address_line' => $preorder->shipment->address_line,
                'province' => $preorder->shipment->province,
                'status' => $preorder->shipment->status,
                'shipped_at' => $preorder->shipment->shipped_at,
                'delivered_at' => $preorder->shipment->delivered_at,
                'notes' => $preorder->shipment->notes,
            ] : null,
        ];
    }
}
