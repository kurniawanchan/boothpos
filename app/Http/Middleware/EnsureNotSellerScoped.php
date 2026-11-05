<?php

namespace App\Http\Middleware;

use App\Support\SellerGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 058 — permukaan administratif / lintas-seller yang tak punya dimensi seller (pengguna, peran, pengaturan,
 * cadangan, log aktivitas, impor/ekspor master data, vendor/bahan, laporan metode bayar). Pengguna yang terikat
 * ke seller ditolak di sini terlepas dari perannya; scope hanya MENYEMPITKAN, tak pernah memperlebar.
 */
class EnsureNotSellerScoped
{
    public function handle(Request $request, Closure $next): Response
    {
        if (SellerGate::active()) {
            return response()->json([
                'code' => 'seller_scope_forbidden',
                'message' => __('seller.scope_forbidden'),
            ], 403);
        }

        return $next($request);
    }
}
