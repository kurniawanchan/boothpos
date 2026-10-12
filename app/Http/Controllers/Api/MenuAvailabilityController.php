<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ItemAvailabilityMark;
use App\Models\MenuRelease;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 043 — GET /menu/availability?since=<etag>: poll ringan untuk POS/lantai (setiap ±5 dtk). Hanya kenyamanan:
 * penolakan sebenarnya tetap di server saat order (MenuAvailability). `304` bila etag sama.
 */
class MenuAvailabilityController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        app(\App\Services\ReleaseService::class)->publishDueCached();

        // 045 Stage 3 — menurut outlet yang berlaku (X-Outlet-Id atau semua yang boleh): tanda global + tanda outlet-outlet itu.
        // `pos=1` (layar POS/tab): outlet shift pengguna, bukan pilihan header — kartu harus meredup menurut tempat
        // order akan dicatat.
        $context = app(\App\Support\OutletContext::class);
        $ordering = $request->boolean('pos') ? $context->orderingId() : null;
        $effective = $ordering !== null ? [$ordering] : $context->effectiveIds();
        $marks = ItemAvailabilityMark::query()->active()
            ->where(fn ($q) => $q->whereNull('outlet_id')->orWhereIn('outlet_id', $effective ?: [0]))
            ->with(['reason:id,code', 'product.variants:id,product_id'])->orderBy('id')->get();
        $discontinued = Product::query()->whereNotNull('discontinued_at')->orderBy('id')->pluck('id')->all();
        $version = (int) (MenuRelease::query()->where('status', MenuRelease::PUBLISHED)->max('version') ?? 0);

        $soldOut = $marks->map(fn ($m) => [
            'product_id' => $m->product_id,
            'outlet_id' => $m->outlet_id,
            'variant_ids' => $m->product?->variants->pluck('id')->values()->all() ?? [],
            'reason_code' => $m->reason?->code,
            'expires_at' => $m->expires_at->toIso8601String(),
        ])->values()->all();

        $etag = md5(json_encode([$soldOut, $discontinued, $version]));
        if ($request->query('since') === $etag) {
            return response()->json(null, 304);
        }

        return response()->json(['etag' => $etag, 'menu_version' => $version, 'sold_out' => $soldOut, 'discontinued_ids' => $discontinued]);
    }
}
