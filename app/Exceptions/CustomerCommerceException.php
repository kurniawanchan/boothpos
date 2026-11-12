<?php

namespace App\Exceptions;

use App\Support\CustomerCommerceProblem;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer commerce (CONTRACT §3) — pelanggaran aturan bisnis fitur toko
 * online yang punya KODE mesin-terbaca (`registration_failed`,
 * `invalid_credentials`, `cart_invalid`, `price_changed`, …). Kembaran
 * SettlementRuleException / BomRuleException: render() dipakai Laravel
 * sehingga controller tetap tipis, tanpa try/catch per endpoint.
 *
 * Bentuk respons mengikuti PERMUKAAN yang memanggil, bukan exception-nya:
 *  - /customer/* dan /storefront/*  → amplop problem+json (aplikasi Android);
 *  - rute staf (mis. fulfilment /customer-orders) → `{message, code}`,
 *    konvensi galat bertipe staf yang sudah ada.
 * Dengan begitu satu service bisa dipakai kedua sisi tanpa membocorkan
 * bentuk pelanggan ke SPA staf.
 *
 * Pesan TIDAK boleh memuat data pribadi atau petunjuk keberadaan akun/pesanan
 * milik orang lain; pakai teks generik dari lang/…/customer_commerce.php.
 *
 * ShouldntReport: ini hasil bisnis yang DIHARAPKAN (kata sandi salah, e-mail
 * sudah terdaftar, keranjang basi) dan bisa dipicu siapa pun dari internet.
 * Melaporkannya berarti satu stack trace tingkat error per percobaan login
 * gagal: log penuh derau dan mudah dibanjiri. Galat tak terduga tetap dilaporkan.
 */
class CustomerCommerceException extends RuntimeException implements ShouldntReport
{
    /**
     * @param  array<string, mixed>|null  $errors  rincian terstruktur (mis. baris keranjang yang bermasalah)
     * @param  array<string, string>  $headers
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly int $status = 409,
        public readonly ?array $errors = null,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>|null  $errors
     * @param  array<string, mixed>  $messageParams
     * @param  array<string, string>  $headers
     */
    public static function make(string $reason, int $status = 409, ?array $errors = null, array $messageParams = [], array $headers = []): self
    {
        $key = "customer_commerce.details.{$reason}";
        $message = __($key, $messageParams);

        // Kode yang belum punya terjemahan tidak boleh menampilkan kunci mentah.
        if (! is_string($message) || $message === $key) {
            $message = (string) __('customer_commerce.details.request_failed');
        }

        return new self($message, $reason, $status, $errors, $headers);
    }

    public function render(Request $request): Response
    {
        if (CustomerCommerceProblem::appliesTo($request)) {
            return CustomerCommerceProblem::response($request, $this->status, $this->reason, $this->getMessage(), $this->errors, $this->headers);
        }

        $body = ['message' => $this->getMessage(), 'code' => $this->reason];

        if ($this->errors !== null) {
            $body['errors'] = $this->errors;
        }

        return response()->json($body, $this->status, $this->headers);
    }
}
