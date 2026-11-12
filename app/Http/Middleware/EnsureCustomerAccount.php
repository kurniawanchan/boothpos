<?php

namespace App\Http\Middleware;

use App\Models\CustomerAccount;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer commerce (CONTRACT §1) — alias `customer.account`, dipasang
 * SETELAH `auth:customer` di setiap rute pelanggan yang butuh login.
 *
 * Gagal TERTUTUP (401 `unauthenticated`) kecuali SEMUA ini benar:
 *  - penggunanya benar-benar CustomerAccount. Guard Sanctum mengembalikan
 *    pengguna guard `web` (sesi) lebih dulu, tanpa memeriksa provider, jadi
 *    `auth:customer` saja belum cukup untuk menolak seorang User;
 *  - akun tidak dinonaktifkan (`disabled_at` null), diperiksa di SETIAP
 *    permintaan. Login staf hanya memeriksa `is_active` saat masuk; pola
 *    itu sengaja tidak ditiru karena penonaktifan harus langsung memutus akses;
 *  - kontak `customers`-nya masih ada di mode data aktif (DEMO/LIVE);
 *  - tokennya membawa ability `customer`.
 *
 * Alias `ability` TIDAK dipakai: itu gerbang menu staf (EnsureMenuAction).
 */
class EnsureCustomerAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user('customer');

        if (! $account instanceof CustomerAccount
            || $account->isDisabled()
            || ! $account->tokenCan(CustomerAccount::TOKEN_ABILITY)
            || $account->customer === null) {
            throw new AuthenticationException(guards: ['customer']);
        }

        return $next($request);
    }
}
