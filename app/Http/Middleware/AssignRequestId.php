<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer commerce (CONTRACT §3) — id korelasi per permintaan untuk grup
 * rute /storefront dan /customer SAJA (rute staf tidak memakainya).
 *
 * Aplikasi Android menampilkan/mengirim id ini saat pelanggan melapor
 * masalah, dan id yang sama ikut di konteks log server, sehingga satu
 * keluhan bisa dicocokkan dengan satu baris log tanpa perlu mencatat token,
 * e-mail, atau data pribadi lain.
 *
 * Id kiriman klien hanya dipakai bila bentuknya aman (8–64 karakter
 * huruf/angka/titik/garis bawah/tanda hubung); selain itu dibuang dan diganti
 * UUID. Nilai bebas dari klien tidak boleh masuk ke header respons maupun log.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    private const ATTRIBUTE = 'customer_commerce.request_id';

    private const PATTERN = '/\A[A-Za-z0-9._-]{8,64}\z/';

    public function handle(Request $request, Closure $next): Response
    {
        $id = self::resolve($request);

        // Context ikut di setiap baris log permintaan ini (termasuk laporan exception).
        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    /**
     * Id untuk permintaan ini; dihitung sekali lalu disimpan di atribut
     * request. Dipanggil juga oleh pembuat amplop galat, karena galat bisa
     * terjadi SEBELUM middleware ini berjalan (rute tidak dikenal, throttle
     * dan auth yang diprioritaskan lebih dulu oleh framework).
     */
    public static function resolve(Request $request): string
    {
        $existing = $request->attributes->get(self::ATTRIBUTE);
        if (is_string($existing)) {
            return $existing;
        }

        $inbound = $request->headers->get(self::HEADER);
        $id = is_string($inbound) && preg_match(self::PATTERN, $inbound) === 1 ? $inbound : (string) Str::uuid();

        $request->attributes->set(self::ATTRIBUTE, $id);

        return $id;
    }
}
