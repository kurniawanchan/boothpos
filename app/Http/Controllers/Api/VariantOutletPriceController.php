<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\ProductVariant;
use App\Models\VariantOutletPrice;
use App\Services\PriceService;
use App\Support\OutletContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 045 Stage 3 — harga varian per outlet. Hanya outlet yang boleh dilihat pengguna yang tampil atau bisa diubah.
 * Penulisan lewat PriceService (satu-satunya penulis harga); tanpa harga khusus = mengikuti harga dasar.
 */
class VariantOutletPriceController extends Controller
{
    public function index(Request $request, ProductVariant $variant, OutletContext $context): JsonResponse
    {
        $this->authorize('update', $variant->product);

        $overrides = VariantOutletPrice::query()->where('variant_id', $variant->id)->pluck('price', 'outlet_id');

        return response()->json([
            'variant_id' => $variant->id,
            'base_price' => number_format((float) $variant->sell_price, 2, '.', ''),
            'outlets' => Outlet::query()->whereIn('id', $context->permittedIds())->orderBy('id')->get(['id', 'code', 'name'])
                ->map(fn (Outlet $o) => [
                    'id' => $o->id, 'code' => $o->code, 'name' => $o->name,
                    'price' => $overrides->has($o->id) ? number_format((float) $overrides[$o->id], 2, '.', '') : null,
                    'follows_base' => ! $overrides->has($o->id),
                ])->values(),
        ]);
    }

    /** `price: null` menghapus harga khusus (kembali mengikuti dasar). */
    public function update(Request $request, ProductVariant $variant, Outlet $outlet, OutletContext $context, PriceService $prices): JsonResponse
    {
        $this->authorize('update', $variant->product);

        if (! $context->isPermitted($outlet->id)) {
            return response()->json(['message' => __('outlets.forbidden'), 'code' => 'outlet_forbidden'], 403);
        }

        $data = $request->validate([
            'price' => ['present', 'nullable', 'numeric', 'min:0', 'max:999999999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $prices->setOutletPrice($variant, $outlet->id, $data['price'], $request->user(), 'edit', $data['note'] ?? null);

        return response()->json([
            'changed' => $result['changed'],
            'pending' => $result['pending'],
            'requires_approval' => $result['requires_approval'],
            'change_id' => $result['change']?->id,
            'outlet_id' => $outlet->id,
            'price' => $data['price'] === null ? null : number_format((float) $data['price'], 2, '.', ''),
            'effective_price' => number_format($prices->priceFor($variant, $outlet->id), 2, '.', ''),
        ]);
    }
}
