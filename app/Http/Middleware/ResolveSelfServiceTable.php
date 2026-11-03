<?php

namespace App\Http\Middleware;

use App\Support\OutletContext;
use App\Support\SelfServiceQrToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 090 — rute publik pesan mandiri: memverifikasi token QR dari path dan MENGIKAT konteks outlet ke outlet token itu.
 *
 * Tanpa pengguna, OutletContext tidak menyaring apa pun (semua outlet aktif). Di sini outlet diikat eksplisit
 * (`bind(null, $outletId)`) sehingga OutletScope, harga outlet, ketersediaan, dan stempel `outlet_id` bekerja untuk
 * outlet QR saja. Outlet TIDAK PERNAH dibaca dari body/query (Konstitusi IV).
 *
 * Meja yang terverifikasi disimpan di atribut permintaan `self_service_table`. Konteks dikembalikan di `finally`
 * supaya tidak menempel pada permintaan berikutnya (pola ResolveOutletContext).
 */
final class ResolveSelfServiceTable
{
    public function __construct(private readonly OutletContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Token tidak sah melempar InvalidSelfServiceQr; renderer di bootstrap/app.php mengubahnya jadi 404/409 JSON.
        $table = SelfServiceQrToken::verify((string) $request->route('token'));

        // Halaman pelanggan SELALU berbahasa Indonesia (FR-002); tidak ada pengguna yang menentukan locale.
        app()->setLocale('id');

        $request->attributes->set('self_service_table', $table);
        $this->context->bind(null, (int) $table->outlet_id);

        try {
            return $next($request);
        } finally {
            $this->context->reset();
        }
    }
}
