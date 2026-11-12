<?php

namespace App\Http\Middleware;

use App\Services\LicenseActivationService;
use App\Support\CustomerCommerceProblem;
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
 *
 * Customer commerce: rute /customer dan /storefront dijawab dengan amplop
 * `application/problem+json` (code `installation_locked`, request_id, bahasa
 * mengikuti Accept-Language) seperti semua galat pelanggan lainnya; aplikasi
 * Android memutuskan tindakan dari `code`, bukan dari `{message, activated}`.
 * Rute staf dan rute lisensi tetap persis seperti sebelumnya.
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
            if (CustomerCommerceProblem::appliesTo($request)) {
                // Gerbang ini berjalan sebelum middleware locale grup pelanggan.
                SetLocaleFromAcceptLanguage::apply($request);

                return CustomerCommerceProblem::response($request, 423, 'installation_locked');
            }

            return response()->json([
                'message' => __('license.installation_locked'),
                'activated' => false,
            ], 423);
        }

        return $next($request);
    }
}
