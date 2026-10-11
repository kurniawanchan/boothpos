<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SoldOutRequest;
use App\Models\Product;
use App\Services\AvailabilityService;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 043 — POST/DELETE /products/{id}/sold-out. Controller tipis: izin di FormRequest/di sini, logika di
 * AvailabilityService (Konstitusi I). Izin `menu.item.86` ditegakkan server; UI hanya menyembunyikan.
 */
class ProductAvailabilityController extends Controller
{
    public function store(SoldOutRequest $request, Product $product, AvailabilityService $service): JsonResponse
    {
        $mark = $service->mark($product, $request->user(), (int) $request->input('reason_code_id'), $request->input('note'));

        return response()->json([
            'product_id' => $product->id,
            'state' => 'sold_out',
            'reason' => $mark->reason?->label(),
            'note' => $mark->note,
            'marked_at' => $mark->marked_at->toIso8601String(),
            'expires_at' => $mark->expires_at->toIso8601String(),
        ], 201);
    }

    public function destroy(Request $request, Product $product, AvailabilityService $service): JsonResponse
    {
        abort_unless($request->user()->hasPermission(PermissionCatalog::ITEM_86), 403, __('reports.not_authorized_generic'));

        $service->undo($product, $request->user());

        return response()->json(['product_id' => $product->id, 'state' => 'available']);
    }
}
