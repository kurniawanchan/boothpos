<?php

use App\Http\Middleware\EnsureInstallationIsActivated;
use App\Support\CustomerCommerceProblem;
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

        // 057 — izin aksi per menu pada rute: `ability:sales,void` (lihat EnsureMenuAction).
        $middleware->alias([
            'ability' => \App\Http\Middleware\EnsureMenuAction::class,
            // 058 — tolak pengguna ber-seller dari permukaan administratif (lihat EnsureNotSellerScoped).
            'seller.forbidden' => \App\Http\Middleware\EnsureNotSellerScoped::class,
            // Customer commerce — HANYA untuk grup rute /customer dan /storefront (lihat routes/api.php).
            // `customer.account`: pengguna guard `customer` yang sah (bukan alias `ability`, itu gerbang menu staf).
            'customer.account' => \App\Http\Middleware\EnsureCustomerAccount::class,
            // `customer.commerce`: saklar fitur `customer_commerce_enabled` (503 saat mati).
            'customer.commerce' => \App\Http\Middleware\EnsureCustomerCommerceEnabled::class,
            // `customer.body`: tolak badan > customer_commerce.max_body_bytes (413) sebelum diurai; paling depan di grup.
            'customer.body' => \App\Http\Middleware\LimitCustomerRequestBody::class,
        ]);

        // Framework mengurutkan middleware yang ada di daftar prioritasnya (auth, throttle) LEBIH DULU
        // daripada yang tidak terdaftar, apa pun urutan di rute. Tanpa ini `customer.body` yang dipasang
        // "paling depan" tetap berjalan SESUDAH `auth:customer` (401 untuk badan raksasa) dan sesudah
        // limiter (badan yang ditolak ikut menghabiskan jatahnya). Ditaruh tepat di depan
        // AuthenticatesRequests, jadi urutannya: customer.body → auth → throttle.
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: \App\Http\Middleware\LimitCustomerRequestBody::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Customer commerce (CONTRACT §3) — amplop galat `application/problem+json` untuk
        // /api/v1/customer/* dan /api/v1/storefront/* SAJA. Callback mengembalikan null
        // untuk permintaan lain, sehingga galat staf tetap ditangani persis seperti
        // sebelumnya (bentuknya dikunci StaffErrorShapeRegressionTest). Didaftarkan
        // SEBELUM renderer QueryException di bawah supaya rute pelanggan tidak pernah
        // menerima bentuk `{message, code}` milik staf; pelaporan ke log tidak diubah.
        $exceptions->render(
            fn (Throwable $e, Request $request) => CustomerCommerceProblem::fromThrowable($e, $request),
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
