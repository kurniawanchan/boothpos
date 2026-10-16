<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesTabErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\SplitTabRequest;
use App\Http\Requests\TabPaymentRequest;
use App\Http\Resources\TabResource;
use App\Models\Order;
use App\Services\TabSettlementService;
use Illuminate\Http\JsonResponse;

/** 028 US2 — pembayaran tab (sebagian/penuh), idempoten lewat `client_ref`. */
class TabPaymentController extends Controller
{
    use HandlesTabErrors;

    public function __construct(private TabSettlementService $settlement, private \App\Services\PaymentService $payments) {}

    /** 048 / S3 — verifikasi QRIS (referensi ≥ 4; persetujuan qris_verify bila perlu). */
    public function verify(\App\Http\Requests\TabPaymentActionRequest $request, Order $order, \App\Models\Payment $payment): JsonResponse
    {
        abort_unless($payment->order_id === $order->id, 404);

        return $this->guarded(fn () => new \App\Http\Resources\TabResource($this->payments->verifyWithApproval(
            $order, $payment, $request->user(), $request->validated('reference'), $request->validated('override_token'),
        )));
    }

    /** 048 / S3 — QRIS tidak ditemukan di mutasi: ditandai rejected (persetujuan qris_fail bila perlu). */
    public function fail(\App\Http\Requests\TabPaymentActionRequest $request, Order $order, \App\Models\Payment $payment): JsonResponse
    {
        abort_unless($payment->order_id === $order->id, 404);

        return $this->guarded(fn () => new \App\Http\Resources\TabResource($this->payments->markRejected(
            $order, $payment, $request->user(), $request->validated('override_token'),
        )));
    }

    /** Pisah tagihan: 201 membuat penjualan terpisah; 200 bila ini pengiriman ulang (client_ref sama). */
    public function split(SplitTabRequest $request, Order $order): JsonResponse
    {
        return $this->guardedWithStatus(function () use ($request, $order) {
            $result = $this->settlement->split($order, $request->validated(), $request->user());

            return [
                ['paid_order' => new TabResource($result['paid_order']), 'tab' => new TabResource($result['tab'])],
                $result['replayed'] ? 200 : 201,
            ];
        });
    }

    public function store(TabPaymentRequest $request, Order $order): JsonResponse
    {
        return $this->guarded(
            fn () => new TabResource($this->settlement->settle($order, $request->validated(), $request->user())),
        );
    }
}
