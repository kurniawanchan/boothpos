<?php

use App\Http\Middleware\EnsureInstallationIsActivated;
use Illuminate\Database\QueryException;
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

        // 035-po-row-actions — database yang TERTINGGAL dari versi aplikasi
        // (kolom/tabel belum ada karena migrasi belum diterapkan) bukan salah
        // pengguna dan bukan bug kode: jawab 503 `schema_outdated` dengan pesan
        // ramah, TANPA SQL/nama tabel/kolom. Pelaporan tidak diubah (callback
        // render tidak menghentikan report), jadi detail lengkap tetap ada di
        // log untuk administrator. Hanya SQLSTATE 42S22 (kolom tidak ada) dan
        // 42S02 (tabel tidak ada); galat database lain TIDAK disamarkan.
        $exceptions->render(function (QueryException $e, Request $request) {
            $isApi = $request->is('api/*') || $request->expectsJson();

            if (! $isApi || ! in_array((string) $e->getCode(), ['42S22', '42S02'], true)) {
                return null;
            }

            return response()->json([
                'message' => __('system.schema_outdated'),
                'code' => 'schema_outdated',
            ], 503);
        });
    })->create();
