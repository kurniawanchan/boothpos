<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesTabErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\HandoverTabRequest;
use App\Http\Requests\MergeTabRequest;
use App\Http\Requests\MoveTabRequest;
use App\Http\Requests\OpenTabRequest;
use App\Http\Requests\UpdateTabNoteRequest;
use App\Http\Requests\VoidTabRequest;
use App\Http\Resources\TabResource;
use App\Models\Order;
use App\Http\Resources\TicketResource;
use App\Services\KitchenService;
use App\Services\TabService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 028 US2 — membuka/melihat/menutup-kosong/serah-terima tab. Aturan bisnis ada di
 * TabService; controller hanya memvalidasi, mendelegasikan, dan membentuk respons.
 */
class TabController extends Controller
{
    use HandlesTabErrors;

    public function __construct(private TabService $tabs, private KitchenService $kitchen) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureFloorAccess($request);

        $rows = Order::query()
            ->where('status', Order::STATUS_OPEN)
            ->where('service_type', '!=', Order::SERVICE_RETAIL)
            ->when($request->filled('session_id'), fn ($q) => $q->where('session_id', $request->integer('session_id')))
            ->when($request->filled('table_id'), fn ($q) => $q->where('table_id', $request->integer('table_id')))
            ->with('table:id,name,area')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $rows->map(fn (Order $o) => [
            'id' => $o->id,
            'order_number' => $o->order_number,
            'status' => $o->status,
            'service_type' => $o->service_type,
            'table' => $o->table ? ['id' => $o->table->id, 'name' => $o->table->name, 'area' => $o->table->area] : null,
            'tab_label' => $o->tab_label,
            'session_id' => $o->session_id,
            'total_amount' => number_format((float) $o->total_amount, 2, '.', ''),
            'paid_amount' => number_format((float) $o->paid_amount, 2, '.', ''),
            'created_at' => $o->created_at?->toIso8601String(),
        ])->values()]);
    }

    public function store(OpenTabRequest $request): JsonResponse
    {
        return $this->guarded(
            fn () => new TabResource($this->tabs->open($request->validated() + ['device_header' => $request->header('X-Device-Id')], $request->user())),
            201,
        );
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->ensureFloorAccess($request);

        // Penjualan ritel dan tab yang sudah dibatalkan/digabung memakai GET /orders/{id}.
        abort_if($order->service_type === Order::SERVICE_RETAIL || $order->status === Order::STATUS_VOIDED, 404);

        return response()->json(new TabResource($this->tabs->loaded($order)));
    }

    public function destroy(Request $request, Order $order): JsonResponse
    {
        $this->ensureFloorAccess($request);

        return $this->guarded(function () use ($order, $request) {
            $this->tabs->closeEmpty($order, $request->user());

            return null;
        }, 204);
    }

    /** Kirim semua baris yang belum terkirim: satu tiket per stasiun (+ satu untuk item tanpa stasiun). */
    public function send(Request $request, Order $order): JsonResponse
    {
        $this->ensureFloorAccess($request);

        return $this->guarded(function () use ($request, $order) {
            $result = $this->kitchen->send($order, $request->user());

            return ['tab' => new TabResource($result['tab']), 'tickets' => TicketResource::collection($result['tickets'])];
        });
    }

    /** 048 / B13 — pra-bill tercetak (tidak butuh shift; kasir dan waiter boleh). */
    /** 077 / US4 — catatan kasir untuk seluruh order; mengembalikan tab terbaru. */
    public function note(UpdateTabNoteRequest $request, Order $order): JsonResponse
    {
        return $this->guarded(fn () => new TabResource($this->tabs->setNote($order, $request->validated('notes'), $request->user())));
    }

    public function prebill(Request $request, Order $order): JsonResponse
    {
        $this->ensureFloorAccess($request);

        return $this->guarded(fn () => new TabResource($this->tabs->prebill($order, $request->user())));
    }

    public function move(MoveTabRequest $request, Order $order): JsonResponse
    {
        return $this->guarded(fn () => new TabResource($this->tabs->move($order, (int) $request->validated('table_id'), $request->user())));
    }

    /** Menggabungkan tab SUMBER (body) ke tab pada URL (target). */
    public function merge(MergeTabRequest $request, Order $order): JsonResponse
    {
        return $this->guarded(fn () => new TabResource($this->tabs->merge($order, (int) $request->validated('source_tab_id'), $request->user())));
    }

    /** Batalkan seluruh tab terbuka (owner/admin, alasan wajib; 409 bila sudah ada pembayaran). */
    /** 048 / S3 — batal seluruh tab: 409 has_payments bila sudah dibayar; persetujuan cancel_order bila perlu. */
    public function cancel(\App\Http\Requests\CancelTabRequest $request, Order $order): JsonResponse
    {
        return $this->guarded(fn () => new TabResource($this->tabs->cancelTab(
            $order, $request->user(), $request->validated('reason_code'), $request->validated('reason'), $request->validated('override_token'),
        )));
    }

    public function void(VoidTabRequest $request, Order $order): JsonResponse
    {
        return $this->guarded(fn () => new TabResource($this->tabs->voidTab($order, $request->validated('reason'), $request->user())));
    }

    public function handover(HandoverTabRequest $request, Order $order): JsonResponse
    {
        return $this->guarded(fn () => new TabResource(
            $this->tabs->handover($order, (int) $request->validated('session_id'), $request->user()),
        ));
    }
}
