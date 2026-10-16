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
        $outletIds = $this->targetOutlets($request);
        if ($outletIds === []) {
            throw \Illuminate\Validation\ValidationException::withMessages(['outlet_ids' => __('overrides.outlet_ids_empty')]);
        }
        $context = app(\App\Support\OutletContext::class);
        $outletId = (int) (($outletIds[0] ?? null) ?? $context->selectedId() ?? $context->singleId() ?? \App\Support\OutletDefaults::forNewRecord());

        // 048 / S3 — penjagaan dan penandaan dalam SATU transaksi: token hanya ikut habis bila tanda tersimpan.
        $mark = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $product, $service, $outletIds, $outletId) {
            app(\App\Services\OverrideGate::class)->requireFor(
                'availability',
                ['product_id' => $product->id, 'reason_code_id' => $request->input('reason_code_id'), 'note' => $request->input('note'), 'outlet_ids' => $outletIds],
                $request->input('override_token'),
                $request->user(),
                $outletId,
                "product:{$product->id}",
            );

            return $service->mark($product, $request->user(), (int) $request->input('reason_code_id'), $request->input('note'), $outletIds);
        });

        return response()->json([
            'product_id' => $product->id,
            'state' => 'sold_out',
            'outlet_ids' => $outletIds,
            'reason' => $mark->reason?->label(),
            'note' => $mark->note,
            'marked_at' => $mark->marked_at->toIso8601String(),
            'expires_at' => $mark->expires_at->toIso8601String(),
        ], 201);
    }

    public function destroy(Request $request, Product $product, AvailabilityService $service): JsonResponse
    {
        abort_unless($request->user()->hasPermission(PermissionCatalog::ITEM_86), 403, __('reports.not_authorized_generic'));

        $outletIds = $this->targetOutlets($request);
        $context = app(\App\Support\OutletContext::class);
        foreach ($outletIds ?? [] as $id) {
            if (! $context->isPermitted($id)) {
                return response()->json(['message' => __('outlets.forbidden'), 'code' => 'outlet_forbidden'], 403);
            }
        }

        $service->undo($product, $request->user(), $outletIds);

        return response()->json(['product_id' => $product->id, 'state' => 'available']);
    }

    /**
     * 045 Stage 3 — outlet yang dituju: `outlet_ids` eksplisit; tanpa itu, outlet yang DIPILIH (X-Outlet-Id) bila ada;
     * selain itu null = tanda GLOBAL (semua outlet, perilaku lama dan instalasi satu outlet).
     *
     * @return list<int>|null
     */
    private function targetOutlets(Request $request): ?array
    {
        if ($request->has('outlet_ids')) {
            return array_map('intval', (array) $request->input('outlet_ids'));
        }

        $selected = app(\App\Support\OutletContext::class)->selectedId();

        return $selected === null ? null : [$selected];
    }
}
