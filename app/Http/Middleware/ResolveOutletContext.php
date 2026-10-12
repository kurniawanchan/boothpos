<?php

namespace App\Http\Middleware;

use App\Support\OutletContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 045 Stage 2 — membaca header `X-Outlet-Id` dan mengisi OutletContext untuk permintaan ini.
 *
 * Dipasang SETELAH auth:sanctum (pengguna sudah diketahui). Aturan (contracts/api-scope.md):
 *  - header tidak ada / kosong → semua outlet yang boleh dilihat;
 *  - bukan angka → 422 `outlet_invalid`;
 *  - angka di luar outlet yang boleh dilihat → 403 `outlet_forbidden`.
 * Tubuh/query `outlet_id` TIDAK pernah dibaca di sini: outlet tidak boleh diperluas dari klien.
 */
final class ResolveOutletContext
{
    public function __construct(private readonly OutletContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $header = $request->header('X-Outlet-Id');
        $selected = null;

        if ($header !== null && $header !== '') {
            if (! ctype_digit((string) $header)) {
                return response()->json(['message' => __('outlets.invalid_header'), 'code' => 'outlet_invalid'], 422);
            }

            $selected = (int) $header;
            $this->context->bind($user, null);

            if (! $this->context->isPermitted($selected)) {
                return response()->json(['message' => __('outlets.forbidden'), 'code' => 'outlet_forbidden'], 403);
            }
        }

        $this->context->bind($user, $selected);

        // Konteks hanya berlaku selama permintaan ini. Tanpa reset, konteks pengguna terakhir tetap
        // menempel pada aplikasi (terutama di tes yang memanggil banyak permintaan berurutan).
        try {
            return $next($request);
        } finally {
            $this->context->reset();
        }
    }
}
