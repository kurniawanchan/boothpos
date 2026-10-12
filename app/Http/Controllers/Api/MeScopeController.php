<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Support\OutletContext;
use Illuminate\Http\JsonResponse;

/**
 * 045 Stage 2 — GET /me/scope: outlet yang boleh dilihat pengguna ini dan pilihan header-nya.
 *
 * Selalu tersedia untuk pengguna yang login (contracts/api-scope.md). `multi` menentukan apakah
 * kendali pemilihan outlet di SPA tampil; di satu outlet ia disembunyikan, bukan dinonaktifkan.
 */
class MeScopeController extends Controller
{
    public function show(OutletContext $context): JsonResponse
    {
        $outlets = Outlet::query()
            ->whereIn('id', $context->permittedIds())
            ->orderBy('id')
            ->get(['id', 'code', 'name'])
            ->map(fn (Outlet $outlet) => ['id' => $outlet->id, 'code' => $outlet->code, 'name' => $outlet->name])
            ->values();

        return response()->json([
            'outlets' => $outlets,
            'selected' => $context->selectedId(),
            'can_see_all' => $context->canSeeAll(),
            'multi' => $context->isMulti(),
        ]);
    }
}
