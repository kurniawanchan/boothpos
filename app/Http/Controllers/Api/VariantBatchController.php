<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VariantBatchRequest;
use App\Models\Product;
use App\Services\VariantBatchService;
use Illuminate\Http\JsonResponse;

/** 043 — PUT /products/{id}/variants (laci Varian & harga). Controller tipis; logika di VariantBatchService. */
class VariantBatchController extends Controller
{
    public function update(VariantBatchRequest $request, Product $product, VariantBatchService $service): JsonResponse
    {
        $result = $service->apply($product, $request->validated('variants'), $request->user(), $request->validated('effective_from'));

        return response()->json($result);
    }
}
