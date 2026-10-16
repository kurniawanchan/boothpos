<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 048 / T066 — akun yang dinonaktifkan kehilangan akses SEKETIKA, termasuk token yang masih hidup di perangkat.
 *
 * Sanctum hanya memeriksa token, bukan status akun: tanpa pemeriksaan ini, staf yang dinonaktifkan di tengah
 * sesi masih bisa membuat order sampai token habis. Token akun ini dicabut di sini juga, sehingga perangkat
 * harus masuk ulang dengan PIN, dan akun yang diaktifkan kembali tidak bisa memakai token lama.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Akun Anda dinonaktifkan. Hubungi administrator.',
                'code' => 'account_inactive',
            ], 401);
        }

        return $next($request);
    }
}
