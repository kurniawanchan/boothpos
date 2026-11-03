<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RejectSelfServiceOrderRequest;
use App\Http\Requests\VerifySelfServiceOrderRequest;
use App\Http\Resources\SelfServiceOrderResource;
use App\Models\SelfServiceOrder;
use App\Services\SelfServiceOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 090 / US6 — tab Self-Service untuk STAF: daftar, detail, verifikasi, tolak, dan "Kirim ke dapur". Semua logika di
 * SelfServiceOrderService; controller hanya memvalidasi, mendelegasikan, dan membentuk respons. Gerbang: menu
 * `self_service`; OutletScope + DataModeScope membuat kiriman outlet/mode lain tidak ditemukan (404).
 */
class SelfServiceOrderController extends Controller
{
    public function __construct(private SelfServiceOrderService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureAccess($request);

        $request->validate([
            'status' => ['sometimes', 'array'],
            'status.*' => [Rule::in([SelfServiceOrder::AWAITING, SelfServiceOrder::VERIFIED, SelfServiceOrder::DISPATCHED, SelfServiceOrder::REJECTED])],
            'table_id' => ['sometimes', 'nullable', 'integer'],
            'q' => ['sometimes', 'nullable', 'string', 'max:60'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        // Hitungan lencana: seluruh outlet/mode yang boleh dilihat, TIDAK ikut filter status/meja/pencarian.
        $counts = SelfServiceOrder::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $pending = ['awaiting_verification', 'verified'];

        $term = trim((string) $request->query('q', ''));
        $page = SelfServiceOrder::query()
            ->with(['channel:id,provider', 'verifier:id,name', 'rejecter:id,name', 'order:id,order_number'])
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', (array) $request->query('status')))
            ->when($request->filled('table_id'), fn ($q) => $q->where('table_id', (int) $request->query('table_id')))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('reference_no', 'like', '%'.$term.'%')->orWhere('table_name_snapshot', 'like', '%'.$term.'%')))
            // Yang menunggu tindakan di atas dan yang TERTUA dulu (antrean); sisanya terbaru dulu.
            ->orderByRaw("CASE WHEN status IN ('awaiting_verification','verified') THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN status IN ('awaiting_verification','verified') THEN id END ASC")
            ->orderByDesc('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return response()->json([
            'data' => $page->getCollection()->map(fn ($o) => new SelfServiceOrderResource($o))->all(),
            'meta' => [
                'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
                'counts' => [
                    'awaiting_verification' => (int) ($counts[SelfServiceOrder::AWAITING] ?? 0),
                    'verified' => (int) ($counts[SelfServiceOrder::VERIFIED] ?? 0),
                    'dispatched' => (int) ($counts[SelfServiceOrder::DISPATCHED] ?? 0),
                    'rejected' => (int) ($counts[SelfServiceOrder::REJECTED] ?? 0),
                    'pending' => (int) collect($pending)->sum(fn ($s) => $counts[$s] ?? 0),
                ],
            ],
        ]);
    }

    /**
     * 090 / US8 — untuk tablet Android: pesanan mandiri BARU sejak kursor `after_id`, supaya tablet mengumumkan
     * "Pesanan dari meja X". Tanpa `after_id` = inisialisasi (tanpa item, hanya kursor + jumlah menunggu): aplikasi yang baru
     * dibuka tidak memutar ulang suara pesanan lama (FR-040). Hanya yang MASIH menunggu verifikasi diumumkan; kursor
     * tetap maju melewati yang sudah diproses staf lain. Tanpa data pelanggan (FR-039). Dipersempit outlet pengguna/perangkat.
     */
    public function alerts(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canAccessAnyMenu(['self_service', 'pos']), 403, __('restaurant.forbidden'));
        $request->validate(['after_id' => ['sometimes', 'integer', 'min:0']]);

        $limit = 20;
        $items = collect();
        if ($request->has('after_id')) {
            $items = SelfServiceOrder::query()
                ->where('id', '>', (int) $request->integer('after_id'))->where('status', SelfServiceOrder::AWAITING)
                ->orderBy('id')->limit($limit)->get(['id', 'table_name_snapshot', 'reference_no', 'total_amount', 'created_at']);
        }

        // Backlog lebih dari batas: kursor berhenti di item terakhir yang dikirim agar sisanya tidak terlewat.
        $lastId = $items->count() === $limit ? (int) $items->last()->id : (int) SelfServiceOrder::query()->max('id');

        return response()->json([
            'items' => $items->map(fn ($o) => [
                'id' => $o->id, 'table_name' => $o->table_name_snapshot, 'reference_no' => $o->reference_no,
                'total_amount' => number_format((float) $o->total_amount, 2, '.', ''), 'created_at' => $o->created_at?->toIso8601String(),
            ])->values(),
            'waiting_count' => SelfServiceOrder::query()->whereIn('status', SelfServiceOrder::PENDING)->count(),
            'last_id' => $lastId,
        ]);
    }

    public function show(Request $request, SelfServiceOrder $selfServiceOrder): JsonResponse
    {
        $this->ensureAccess($request);

        return response()->json(['data' => $this->present($selfServiceOrder)]);
    }

    public function verify(VerifySelfServiceOrderRequest $request, SelfServiceOrder $selfServiceOrder): JsonResponse
    {
        $row = $this->service->verify($selfServiceOrder, (string) $request->input('reference'), $request->user());

        return response()->json(['data' => $this->present($row)]);
    }

    public function reject(RejectSelfServiceOrderRequest $request, SelfServiceOrder $selfServiceOrder): JsonResponse
    {
        $row = $this->service->reject($selfServiceOrder, (string) $request->input('reason'), $request->user());

        return response()->json(['data' => $this->present($row)]);
    }

    public function dispatch(Request $request, SelfServiceOrder $selfServiceOrder): JsonResponse
    {
        $this->ensureAccess($request);

        $result = $this->service->dispatch($selfServiceOrder, $request->user());

        return response()->json([
            'data' => $this->present($result['submission']),
            'order' => ['id' => $result['order']->id, 'order_number' => $result['order']->order_number],
            'tickets' => $result['tickets'],
        ]);
    }

    private function present(SelfServiceOrder $row): SelfServiceOrderResource
    {
        return new SelfServiceOrderResource($row->load(['channel:id,provider', 'verifier:id,name', 'rejecter:id,name', 'order:id,order_number']), true);
    }

    private function ensureAccess(Request $request): void
    {
        abort_unless($request->user()?->canAccessMenu('self_service'), 403, __('restaurant.forbidden'));
    }
}
