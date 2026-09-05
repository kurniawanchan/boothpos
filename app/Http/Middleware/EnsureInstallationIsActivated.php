<?php

namespace App\Http\Middleware;

use App\Services\LicenseActivationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 018-license-activation — gerbang GLOBAL di level server (Constitution
 * IV: "setiap keputusan access-control HARUS ditegakkan di server-side;
 * menyembunyikan tombol di UI hanya kosmetik"). Terdaftar di seluruh
 * grup middleware 'api' (bootstrap/app.php) — TIDAK ADA endpoint yang
 * dikecualikan selain dua endpoint lisensi itu sendiri, TERMASUK
 * /auth/login (FR-002: "tidak ada pengecualian").
 */
class EnsureInstallationIsActivated
{
    private const EXEMPT_PATHS = [
        'api/v1/license/status',
        'api/v1/license/activate',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is(...self::EXEMPT_PATHS)) {
            return $next($request);
        }

        if (! app(LicenseActivationService::class)->isActivated()) {
            return response()->json([
                'message' => __('license.installation_locked'),
                'activated' => false,
            ], 423);
        }

        return $next($request);
    }
}
