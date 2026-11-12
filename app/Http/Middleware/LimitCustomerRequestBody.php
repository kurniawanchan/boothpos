<?php

namespace App\Http\Middleware;

use App\Support\CustomerCommerceProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer commerce (CONTRACT §3) — alias `customer.body`: badan permintaan
 * yang lebih besar dari `customer_commerce.max_body_bytes` ditolak 413
 * `payload_too_large` SEBELUM ada yang mengurai atau memvalidasinya.
 *
 * Rute pelanggan/etalase publik dan JSON-nya kecil (keranjang paling banyak
 * `max_lines_per_order` baris, beberapa KB). Tanpa batas ini satu permintaan
 * raksasa bisa membuat pembatas laju (yang membaca `email` dari badan), hash
 * kata sandi, dan validasi `distinct` (kuadratik terhadap jumlah baris)
 * bekerja pada masukan sebesar apa pun yang diterima server.
 *
 * Dipasang PALING DEPAN di kedua grup rute: pembatas laju membaca badan
 * (`$request->input('email')`) dan harus sudah tidak sampai ke sana untuk
 * badan yang terlalu besar. Permintaan yang ditolak di sini juga tidak
 * menghabiskan jatah pembatas laju.
 *
 * Ukuran diambil dari header Content-Length bila ada (murah, tanpa membaca
 * badan). Tanpa header (mis. chunked), badan dibaca paling banyak
 * `batas + 1` byte, bukan seluruhnya.
 */
class LimitCustomerRequestBody
{
    public function handle(Request $request, Closure $next): Response
    {
        $limit = max(1, (int) config('customer_commerce.max_body_bytes'));

        if ($this->bodyBytes($request, $limit) > $limit) {
            // Locale biasanya diatur middleware sesudah ini; galat ini terjadi sebelum itu.
            SetLocaleFromAcceptLanguage::apply($request);

            return CustomerCommerceProblem::response($request, 413, 'payload_too_large');
        }

        return $next($request);
    }

    /** Ukuran badan dalam byte; bila tanpa Content-Length, dibaca paling banyak $limit + 1 byte. */
    private function bodyBytes(Request $request, int $limit): int
    {
        $declared = $request->headers->get('Content-Length');

        if (is_string($declared) && ctype_digit($declared)) {
            return (int) $declared;
        }

        $body = $request->getContent(true);

        return is_resource($body)
            ? strlen((string) stream_get_contents($body, $limit + 1))
            : strlen($body);
    }
}
