<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FloorQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 028 US1 — GET /floor, gerbang menu `floor`. Dipanggil polling setiap ±3 dtk,
 * jadi jalur "tidak ada perubahan" harus murah: bila token `version` klien
 * sama, kembalikan kerangka kecil saja.
 */
class FloorController extends Controller
{
    public function __construct(private FloorQuery $floor) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canAccessMenu('floor'), 403, __('restaurant.forbidden'));

        $payload = $this->floor->build();

        if ($request->filled('version') && hash_equals($payload['version'], (string) $request->query('version'))) {
            return response()->json(['unchanged' => true, 'version' => $payload['version']]);
        }

        return response()->json($payload);
    }
}
