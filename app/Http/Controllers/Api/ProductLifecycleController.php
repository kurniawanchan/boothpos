<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DiscontinueRequest;
use App\Models\Product;
use App\Services\ProductLifecycleService;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 043 — POST /products/{id}/discontinue | reactivate. Izin `menu.item.manage`; logika di ProductLifecycleService. */
class ProductLifecycleController extends Controller
{
    public function discontinue(DiscontinueRequest $request, Product $product, ProductLifecycleService $service): JsonResponse
    {
        $result = $service->discontinue($product, $request->user(), (int) $request->input('reason_code_id'), $request->input('note'));

        return $this->present($result);
    }

    public function reactivate(Request $request, Product $product, ProductLifecycleService $service): JsonResponse
    {
        abort_unless($request->user()->hasPermission(PermissionCatalog::ITEM_MANAGE), 403, __('reports.not_authorized_generic'));

        return $this->present($service->reactivate($product, $request->user()));
    }

    /** @param array{product: Product, change: \App\Models\MenuChange|null} $result */
    private function present(array $result): JsonResponse
    {
        $p = $result['product'];

        return response()->json([
            'id' => $p->id,
            'status' => $p->status,
            'is_active' => (bool) $p->is_active,
            // Mode rilis: perubahan menunggu rilis berikutnya; status item BELUM berubah.
            'change' => $result['change'] ? ['id' => $result['change']->id, 'kind' => $result['change']->kind, 'status' => $result['change']->status] : null,
        ]);
    }
}
