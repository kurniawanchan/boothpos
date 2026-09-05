<?php

use App\Http\Middleware\EnsureInstallationIsActivated;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 018-license-activation — gerbang lisensi global, di DEPAN
        // seluruh route API lainnya (termasuk /auth/login, lihat FR-002).
        // Prepend, bukan append, supaya request yang belum ter-lisensi
        // ditolak sedini mungkin, sebelum middleware lain (mis. auth)
        // sempat memproses apa pun.
        $middleware->api(prepend: [EnsureInstallationIsActivated::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
