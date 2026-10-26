<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settlement\AcknowledgeRequest;
use App\Http\Requests\Settlement\AdjustmentRequest;
use App\Http\Requests\Settlement\SettlementPaymentRequest;
use App\Http\Requests\Settlement\StoreSettlementRequest;
use App\Http\Resources\SellerSettlementDetailResource;
use App\Http\Resources\SellerSettlementResource;
use App\Models\Artist;
use App\Models\Event;
use App\Models\SellerSettlement;
use App\Models\SellerSettlementAcknowledgement;
use App\Models\SellerSettlementAdjustment;
use App\Models\SellerSettlementPayment;
use App\Services\SellerSettlementBuilder;
use App\Services\SellerSettlementService;
use App\Support\ReportSplit;
use App\Support\SettlementDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * 076-settlement-tab-session-branding — modul settlement seller. Controller
 * tipis: validasi → delegasi ke SellerSettlementService (satu-satunya
 * penulis) → bentuk respons. Semua angka dibentuk server.
 *
 * Penegakan akses: FormRequest::authorize() untuk request ber-body, dan
 * guard menu di authorizeMenu() untuk sisanya (baca + aksi tanpa body).
 * Aturan bisnis yang dilanggar dilempar service sebagai
 * SettlementRuleException (409 + code) — dirender oleh exception itu sendiri.
 *
 * Setiap aksi tulis mengembalikan DETAIL settlement terbaru supaya SPA
 * tidak perlu memuat ulang.
 */
class SellerSettlementController extends Controller
{
    public function __construct(
        private readonly SellerSettlementService $service,
        private readonly SellerSettlementBuilder $builder,
        private readonly SettlementDocument $documents,
    ) {}

    private function authorizeMenu(Request $request): void
    {
        abort_unless($request->user()?->canAccessMenu('settlements'), 403, __('settlements.not_authorized'));
    }

    private function detail(SellerSettlement $settlement, int $status = 200): JsonResponse
    {
        $fresh = $this->service->loadDetail($settlement->fresh());

        return (new SellerSettlementDetailResource($fresh, $this->service->sourceChanged($fresh)))
            ->response()
            ->setStatusCode($status);
    }

    // ------------------------------------------------------------------
    // Daftar & pratinjau
    // ------------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenu($request);

        $filters = $request->validate([
            'event_id' => ['nullable', 'integer'],
            'artist_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:draft,finalized,acknowledged,partially_paid,settled'],
            'payment_status' => ['nullable', 'in:net_zero,unpaid,partially_paid,settled'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = SellerSettlement::query()
            ->when($filters['event_id'] ?? null, fn ($q, $id) => $q->where('event_id', $id))
            ->when($filters['artist_id'] ?? null, fn ($q, $id) => $q->where('artist_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $this->filterDisplayStatus($q, $status))
            ->when($filters['payment_status'] ?? null, fn ($q, $status) => $this->filterPaymentStatus($q, $status));

        // Total mengikuti BARIS TERFILTER (bukan seluruh tabel), sama dengan yang tampil.
        $totals = (clone $query)->selectRaw('
            COALESCE(SUM(revenue_total), 0) as revenue_total,
            COALESCE(SUM(net_amount), 0) as net_amount,
            COALESCE(SUM(paid_amount), 0) as paid_amount,
            COALESCE(SUM(GREATEST(ABS(net_amount) - paid_amount, 0)), 0) as balance
        ')->first();

        $page = $query->with('currentAcknowledgement')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'data' => SellerSettlementResource::collection($page->getCollection())->resolve(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'totals' => [
                'revenue_total' => ReportSplit::money(ReportSplit::cents($totals->revenue_total)),
                'net_amount' => ReportSplit::money(ReportSplit::cents($totals->net_amount)),
                'paid_amount' => ReportSplit::money(ReportSplit::cents($totals->paid_amount)),
                'balance' => ReportSplit::money(ReportSplit::cents($totals->balance)),
            ],
        ]);
    }

    /** Status tampilan diterjemahkan ke kondisi SQL (pengakuan = ada baris yang belum digantikan). */
    private function filterDisplayStatus($query, string $status)
    {
        $acknowledged = fn ($q) => $q->selectRaw('1')->from('seller_settlement_acknowledgements as ack')
            ->whereColumn('ack.settlement_id', 'seller_settlements.id')->whereNull('ack.superseded_at');

        return match ($status) {
            'draft' => $query->where('status', 'draft'),
            'finalized' => $query->where('status', 'finalized')->whereNotExists($acknowledged),
            'acknowledged' => $query->where('status', 'finalized')->whereExists($acknowledged)
                ->where('paid_amount', 0)->where('net_amount', '<>', 0),
            'partially_paid' => $query->where('status', 'finalized')->whereExists($acknowledged)
                ->where('paid_amount', '>', 0)->whereRaw('paid_amount < ABS(net_amount)'),
            'settled' => $query->where('status', 'finalized')->whereExists($acknowledged)
                ->whereRaw('paid_amount = ABS(net_amount)'),
        };
    }

    private function filterPaymentStatus($query, string $status)
    {
        return match ($status) {
            'net_zero' => $query->where('net_amount', 0),
            'unpaid' => $query->where('net_amount', '<>', 0)->where('paid_amount', 0),
            'partially_paid' => $query->where('paid_amount', '>', 0)->whereRaw('paid_amount < ABS(net_amount)'),
            'settled' => $query->where('net_amount', '<>', 0)->whereRaw('paid_amount = ABS(net_amount)'),
        };
    }

    /**
     * Isi settlement yang AKAN dibentuk, tanpa menyimpan apa pun. Seller tanpa
     * aktivitas menghasilkan nol, bukan galat. `existing_settlement_id` memberi
     * tahu UI bila pasangan ini sudah punya settlement aktif.
     */
    public function preview(Request $request): JsonResponse
    {
        $this->authorizeMenu($request);

        $validated = $request->validate([
            'event_id' => ['required', 'integer'],
            'artist_id' => ['required', 'integer'],
        ]);

        $event = Event::findOrFail($validated['event_id']);
        $artist = Artist::findOrFail($validated['artist_id']);
        $built = $this->builder->build($event->id, $artist->id);
        $totals = $built['totals'];
        $cogs = ReportSplit::cents($totals['memo_cogs_pos']) + ReportSplit::cents($totals['memo_cogs_preorder']);

        return response()->json(['data' => [
            'event' => ['id' => $event->id, 'name' => $event->name],
            'seller' => ['id' => $artist->id, 'name' => $artist->name, 'code' => $artist->code],
            'pos_units' => $totals['pos_units'],
            'pos_revenue' => $totals['pos_revenue'],
            'preorder_units' => $totals['preorder_units'],
            'preorder_revenue' => $totals['preorder_revenue'],
            'revenue_total' => $totals['revenue_total'],
            'adjustments_total' => '0.00',
            'net_amount' => $totals['revenue_total'],
            // Belum tersimpan: bentuk sama dengan baris sumber beku, tanpa id.
            'sources' => collect($built['sources'])->map(fn (array $row) => ['id' => null] + $row + [
                'source_date' => null,
            ])->map(fn (array $row) => array_replace($row, [
                'source_date' => $row['source_date'] ? \Carbon\Carbon::parse($row['source_date'])->toIso8601String() : null,
            ]))->values()->all(),
            'memo' => [
                'preorder_uncollected' => $totals['memo_preorder_uncollected'],
                'cost_of_goods' => ReportSplit::money($cogs),
                'gross_profit' => ReportSplit::money(ReportSplit::cents($totals['revenue_total']) - $cogs),
            ],
            'existing_settlement_id' => SellerSettlement::where('event_id', $event->id)->where('artist_id', $artist->id)->value('id'),
        ]]);
    }

    // ------------------------------------------------------------------
    // Siklus draf
    // ------------------------------------------------------------------

    public function store(StoreSettlementRequest $request): JsonResponse
    {
        $settlement = $this->service->create($request->integer('event_id'), $request->integer('artist_id'), $request->user());

        return $this->detail($settlement, 201);
    }

    public function show(Request $request, SellerSettlement $settlement): JsonResponse
    {
        $this->authorizeMenu($request);

        return $this->detail($settlement);
    }

    public function refresh(Request $request, SellerSettlement $settlement): JsonResponse
    {
        $this->authorizeMenu($request);

        return $this->detail($this->service->refresh($settlement, $request->user()));
    }

    public function destroy(Request $request, SellerSettlement $settlement): Response
    {
        $this->authorizeMenu($request);

        $this->service->delete($settlement, $request->user());

        return response()->noContent();
    }

    public function finalize(Request $request, SellerSettlement $settlement): JsonResponse
    {
        $this->authorizeMenu($request);

        return $this->detail($this->service->finalize($settlement, $request->user()));
    }

    public function reopen(Request $request, SellerSettlement $settlement): JsonResponse
    {
        $this->authorizeMenu($request);

        return $this->detail($this->service->reopen($settlement, $request->user()));
    }

    // ------------------------------------------------------------------
    // Potongan & penyesuaian
    // ------------------------------------------------------------------

    public function storeAdjustment(AdjustmentRequest $request, SellerSettlement $settlement): JsonResponse
    {
        return $this->detail($this->service->addAdjustment($settlement, $request->validated(), $request->user()), 201);
    }

    public function updateAdjustment(AdjustmentRequest $request, SellerSettlement $settlement, SellerSettlementAdjustment $adjustment): JsonResponse
    {
        return $this->detail($this->service->updateAdjustment($settlement, $adjustment, $request->validated(), $request->user()));
    }

    public function destroyAdjustment(Request $request, SellerSettlement $settlement, SellerSettlementAdjustment $adjustment): JsonResponse
    {
        $this->authorizeMenu($request);

        return $this->detail($this->service->removeAdjustment($settlement, $adjustment, $request->user()));
    }

    // ------------------------------------------------------------------
    // Pengakuan seller
    // ------------------------------------------------------------------

    public function acknowledge(AcknowledgeRequest $request, SellerSettlement $settlement): JsonResponse
    {
        $updated = $this->service->acknowledge($settlement, $request->validated(), $request->file('signature'), $request->user());

        return $this->detail($updated, 201);
    }

    /** Gambar tanda tangan dari disk PRIVAT, hanya lewat endpoint yang memeriksa hak akses. */
    public function signature(Request $request, SellerSettlement $settlement, SellerSettlementAcknowledgement $acknowledgement): Response
    {
        $this->authorizeMenu($request);
        abort_unless($acknowledgement->settlement_id === $settlement->id && $acknowledgement->signature_path, 404);

        return $this->privateFile($acknowledgement->signature_path);
    }

    // ------------------------------------------------------------------
    // Pembayaran
    // ------------------------------------------------------------------

    public function storePayment(SettlementPaymentRequest $request, SellerSettlement $settlement): JsonResponse
    {
        [$updated, $replayed] = $this->service->addPayment($settlement, $request->validated(), $request->file('proof'), $request->user());

        // Pengiriman ulang dengan client_ref yang sama: 200, tidak ada baris baru.
        return $this->detail($updated, $replayed ? 200 : 201);
    }

    public function destroyPayment(Request $request, SellerSettlement $settlement, SellerSettlementPayment $payment): JsonResponse
    {
        $this->authorizeMenu($request);

        return $this->detail($this->service->deletePayment($settlement, $payment, $request->user()));
    }

    public function paymentProof(Request $request, SellerSettlement $settlement, SellerSettlementPayment $payment): Response
    {
        $this->authorizeMenu($request);
        abort_unless($payment->settlement_id === $settlement->id && $payment->proof_path, 404);

        return $this->privateFile($payment->proof_path);
    }

    // ------------------------------------------------------------------
    // Dokumen cetak (dirender di browser)
    // ------------------------------------------------------------------

    public function document(Request $request, SellerSettlement $settlement): JsonResponse
    {
        $this->authorizeMenu($request);
        $includeCost = $request->boolean('include_cost');

        return response()->json(['data' => $this->documents->forSettlement($settlement, $includeCost)]);
    }

    public function paymentDocument(Request $request, SellerSettlement $settlement, SellerSettlementPayment $payment): JsonResponse
    {
        $this->authorizeMenu($request);
        abort_unless($payment->settlement_id === $settlement->id, 404);

        return response()->json(['data' => $this->documents->forPayment($settlement, $payment)]);
    }

    /** Berkas privat: tidak pernah di-cache oleh browser/proxy. */
    private function privateFile(string $path): Response
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        return response($disk->get($path), 200, [
            'Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
