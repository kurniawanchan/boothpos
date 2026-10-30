<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ModifierLinksRequest;
use App\Models\Product;
use App\Services\ModifierLinkService;
use Illuminate\Http\JsonResponse;

/** 043 — PUT /products/{id}/modifier-links (laci Grup modifier). Controller tipis; logika di ModifierLinkService. */
class ModifierLinkController extends Controller
{
    public function update(ModifierLinksRequest $request, Product $product, ModifierLinkService $service): JsonResponse
    {
        // 086 — `validated('excluded_group_ids')` bernilai null bila klien tak mengirim kuncinya (pengecualian tak berubah).
        $result = $service->replace($product, $request->validated('links'), $request->user(), $request->validated('excluded_group_ids'));

        return response()->json([
            'applied' => $result['applied'],
            'change' => $result['change'] ? ['id' => $result['change']->id, 'kind' => $result['change']->kind, 'status' => $result['change']->status] : null,
        ]);
    }
}
