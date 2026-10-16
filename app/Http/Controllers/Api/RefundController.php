<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RefundRequest;
use App\Models\Order;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;

/** 048 / T060 — refund penuh order lunas (token wajib). */
class RefundController extends Controller
{
    public function store(RefundRequest $request, Order $order, RefundService $refunds): JsonResponse
    {
        $v = $request->validated();

        return response()->json($refunds->refund($order, $request->user(), $v['reason_code'], $v['reason'] ?? null, $v['override_token'] ?? null));
    }
}
