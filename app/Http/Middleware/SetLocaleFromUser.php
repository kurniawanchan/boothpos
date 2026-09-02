<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Satu-satunya tempat locale backend diputuskan (Constitution Principle
 * I) — tidak ada endpoint yang boleh menerka locale-nya sendiri secara
 * terpisah. Didaftarkan HANYA pada grup route auth:sanctum, SETELAH
 * middleware autentikasi (butuh $request->user() sudah resolve).
 * SENGAJA TIDAK didaftarkan pada POST /auth/login — rute itu tetap
 * memakai locale default aplikasi ('id', lihat config/app.php), karena
 * layar login harus selalu Bahasa Indonesia untuk semua orang (FR-001,
 * 002-language-toggle).
 */
class SetLocaleFromUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            App::setLocale($user->language);
        }

        return $next($request);
    }
}
