<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 045 Stage 5 — satu aturan untuk "outlet mana yang stoknya diubah" (penyesuaian item dan bahan). */
trait ResolvesStockOutlet
{
    /**
     * 045 Stage 5 — outlet untuk penyesuaian stok: harus termasuk outlet yang boleh dilihat (403), dan wajib dipilih bila
     * ada beberapa (422). Satu outlet = outlet itu, tanpa langkah tambahan.
     */
    private function stockOutlet(Request $request, ?int $requested): int|JsonResponse
    {
        $context = app(\App\Support\OutletContext::class);
        $permitted = $context->permittedIds();

        if ($requested !== null) {
            return $context->isPermitted($requested)
                ? $requested
                : response()->json(['message' => __('outlets.forbidden'), 'code' => 'outlet_forbidden'], 403);
        }

        $single = $context->singleId() ?? (count($permitted) === 1 ? $permitted[0] : null);
        if ($single !== null) {
            return $single;
        }

        if ($permitted === []) {
            return response()->json(['message' => __('outlets.unassigned'), 'code' => 'outlet_unassigned'], 409);
        }

        return response()->json([
            'message' => __('stock.outlet_required'),
            'errors' => ['outlet_id' => [__('stock.outlet_required')]],
        ], 422);
    }
}
