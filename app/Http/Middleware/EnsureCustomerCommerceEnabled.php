<?php

namespace App\Http\Middleware;

use App\Support\CustomerCommerceGate;
use App\Support\CustomerCommerceProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer commerce (CONTRACT §2) — alias `customer.commerce`: saklar fitur
 * ditegakkan di server. Saat mati, rute yang MEMBUAT hal baru (etalase,
 * pendaftaran, checkout, tulis alamat) menjawab 503
 * `customer_commerce_disabled`.
 *
 * SENGAJA tidak dipasang pada login, logout, profil, daftar alamat, dan
 * baca pesanan: mematikan fitur adalah jalan mundur yang menghentikan
 * pesanan BARU sambil tetap membiarkan pelanggan mengikuti pesanan yang
 * sudah dikirim (design.md "Rollout"). Rute staf juga tidak pernah digerbang
 * olehnya, supaya pesanan yang sudah masuk tetap bisa diselesaikan.
 */
class EnsureCustomerCommerceEnabled
{
    /** Petunjuk bagi aplikasi kapan pantas mencoba lagi (detik). */
    private const RETRY_AFTER_SECONDS = 3600;

    public function handle(Request $request, Closure $next): Response
    {
        if (! CustomerCommerceGate::enabled()) {
            return CustomerCommerceProblem::response(
                $request,
                503,
                'customer_commerce_disabled',
                headers: ['Retry-After' => (string) self::RETRY_AFTER_SECONDS],
            );
        }

        return $next($request);
    }
}
