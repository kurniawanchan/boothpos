<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PriceChangeRequest;
use App\Services\BulkPriceService;
use Illuminate\Http\JsonResponse;

/** 043 — POST /price-changes/preview | /price-changes. Controller tipis; logika di BulkPriceService. */
class PriceChangeController extends Controller
{
    public function preview(PriceChangeRequest $request, BulkPriceService $service): JsonResponse
    {
        return response()->json($service->preview($request->validated()));
    }

    public function store(PriceChangeRequest $request, BulkPriceService $service): JsonResponse
    {
        return response()->json($service->apply($request->validated(), $request->user()), 201);
    }
}
