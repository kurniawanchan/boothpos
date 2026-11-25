<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\ManagerSessionController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 094 / FR-005a — sesi aplikasi manajemen diperiksa ULANG tiap permintaan. Sanctum hanya memeriksa token, jadi tanpa ini
 * mematikan "Boleh memakai aplikasi manajemen" di formulir Peran baru berlaku setelah token habis. Hanya token bernama
 * `manager-app` yang disentuh: sesi web (`pos-session`) dan PIN tablet (`pos-pin`) pengguna yang sama tetap jalan.
 */
class EnsureManagerAppAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($token !== null && ($token->name ?? null) === ManagerSessionController::TOKEN_NAME && ! $user->role?->manager_app_enabled) {
            $token->delete();

            return response()->json([
                'message' => 'Akun ini tidak punya akses ke aplikasi manajemen.',
                'code' => 'manager_app_denied',
            ], 403);
        }

        return $next($request);
    }
}
