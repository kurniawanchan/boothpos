<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer commerce (CONTRACT §3) — bahasa pesan untuk grup rute /storefront
 * dan /customer: Indonesia secara bawaan, Inggris bila `Accept-Language`
 * memintanya.
 *
 * SetLocaleFromUser milik staf TIDAK dipakai di sini: ia membaca
 * `users.language`, kolom yang tidak dimiliki akun pelanggan (hasilnya
 * locale null). Locale hanya pernah diambil dari daftar izin di bawah,
 * tidak pernah langsung dari isi header.
 */
class SetLocaleFromAcceptLanguage
{
    /** Urutan penting: entri pertama adalah bawaan saat header tidak ada / tidak cocok. */
    public const SUPPORTED = ['id', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        self::apply($request);

        return $next($request);
    }

    /**
     * Dipanggil juga oleh pembuat amplop galat: galat yang terjadi sebelum
     * middleware ini berjalan (401 dari guard, 429 dari throttle, 404 rute
     * tidak dikenal) tetap harus memakai bahasa yang diminta klien.
     */
    public static function apply(Request $request): string
    {
        $locale = $request->getPreferredLanguage(self::SUPPORTED);
        $locale = in_array($locale, self::SUPPORTED, true) ? $locale : self::SUPPORTED[0];

        App::setLocale($locale);

        return $locale;
    }
}
