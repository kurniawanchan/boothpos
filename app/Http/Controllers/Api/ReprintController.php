<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReprintRequest;
use App\Models\Order;
use App\Services\ReprintService;
use Illuminate\Http\JsonResponse;

/** 048 / T060 — cetak ulang struk (salinan pertama gratis; berikutnya butuh persetujuan). */
class ReprintController extends Controller
{
    public function store(ReprintRequest $request, Order $order, ReprintService $reprints): JsonResponse
    {
        return response()->json($reprints->reprint($order, $request->user(), (int) $request->validated('copy_no'), $request->validated('override_token')));
    }
}
