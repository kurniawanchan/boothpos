<?php

namespace App\Support;

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\SetLocaleFromAcceptLanguage;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Customer commerce (CONTRACT §3) — SATU-satunya pembentuk amplop galat
 * `application/problem+json` (gaya RFC 9457) untuk /api/v1/customer/* dan
 * /api/v1/storefront/*.
 *
 * Bentuknya kontrak dengan aplikasi Android (yang memutuskan tindakan dari
 * `code`), jadi tidak ada tempat lain yang boleh menyusun badan galat untuk
 * dua awalan itu. Galat STAF tidak pernah lewat sini: appliesTo() adalah
 * gerbangnya, dan StaffErrorShapeRegressionTest mengunci bentuk lama staf.
 *
 * Aturan isi: `detail` selalu teks dari lang/{id,en}/customer_commerce.php,
 * TIDAK PERNAH pesan exception (yang bisa memuat nama kelas model, SQL,
 * jalur berkas, atau data pelanggan lain).
 */
final class CustomerCommerceProblem
{
    public const CONTENT_TYPE = 'application/problem+json';

    private const TYPE_BASE = 'https://boothpos.local/problems/';

    /** Status HTTP → kode mesin untuk galat HTTP umum (4xx lain: `request_failed`). */
    private const HTTP_CODES = [
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        // Juga badan yang melebihi `post_max_size` PHP (PostTooLargeException milik framework).
        413 => 'payload_too_large',
        422 => 'validation_failed',
        429 => 'too_many_requests',
    ];

    /** Header exception HTTP yang aman dan berguna bagi klien; sisanya dibuang. */
    private const PASSTHROUGH_HEADERS = ['Retry-After', 'Allow', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'];

    public static function appliesTo(Request $request): bool
    {
        return $request->is('api/v1/customer', 'api/v1/customer/*', 'api/v1/storefront', 'api/v1/storefront/*');
    }

    /**
     * @param  array<string, mixed>|null  $errors  hanya bila relevan (galat per-field 422, baris keranjang 409)
     * @param  array<string, string>  $headers
     */
    public static function response(
        Request $request,
        int $status,
        string $code,
        ?string $detail = null,
        ?array $errors = null,
        array $headers = [],
    ): JsonResponse {
        $requestId = AssignRequestId::resolve($request);

        $body = [
            'type' => self::TYPE_BASE.$code,
            'title' => self::line("titles.{$code}"),
            'status' => $status,
            'detail' => $detail ?? self::line("details.{$code}"),
            'code' => $code,
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        $body['request_id'] = $requestId;

        return response()->json(
            $body,
            $status,
            $headers + ['Content-Type' => self::CONTENT_TYPE, AssignRequestId::HEADER => $requestId],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Renderer exception untuk bootstrap/app.php. Mengembalikan null untuk
     * permintaan di luar dua awalan pelanggan, sehingga framework meneruskan
     * ke perilaku lama (bentuk staf tidak berubah satu byte pun).
     *
     * Dipanggil SETELAH Handler::prepareException(), jadi ModelNotFound sudah
     * menjadi NotFoundHttpException dan AuthorizationException sudah menjadi
     * AccessDeniedHttpException: cukup memetakan status HTTP-nya.
     */
    public static function fromThrowable(Throwable $e, Request $request): ?Response
    {
        if (! self::appliesTo($request)) {
            return null;
        }

        // Respons yang sudah jadi (mis. dari FormRequest khusus) tidak dibentuk ulang.
        if ($e instanceof HttpResponseException) {
            return null;
        }

        // Galat bisa terjadi sebelum middleware locale berjalan (guard, throttle, rute tidak dikenal).
        SetLocaleFromAcceptLanguage::apply($request);

        if ($e instanceof ValidationException) {
            return self::response($request, $e->status, 'validation_failed', errors: self::humaniseValidationErrors($e->errors()));
        }

        if ($e instanceof AuthenticationException) {
            return self::response($request, 401, 'unauthenticated');
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $code = $status >= 500 ? 'server_error' : (self::HTTP_CODES[$status] ?? 'request_failed');

            return self::response($request, $status, $code, headers: self::safeHeaders($e->getHeaders()));
        }

        return self::response($request, 500, 'server_error');
    }

    /** @return array<string, string> */
    private static function safeHeaders(array $headers): array
    {
        $safe = [];

        foreach (self::PASSTHROUGH_HEADERS as $name) {
            foreach ($headers as $key => $value) {
                if (strcasecmp((string) $key, $name) === 0) {
                    $safe[$name] = (string) $value;
                }
            }
        }

        return $safe;
    }

    /**
     * Proyek ini tidak punya teks validasi (lang/{id,en}/validation.php kosong), jadi pesan bawaan
     * Laravel adalah KUNCI-nya ("validation.in", "validation.max.string"). Aplikasi Android
     * menampilkan pesan per-field apa adanya, jadi kunci itu diganti kalimat dari
     * customer_commerce.validation_rules. Sengaja DI SINI dan bukan lang/validation.php global:
     * galat staf harus tetap persis seperti sebelumnya (SPA staf dan aplikasi POS membacanya).
     * Pesan yang sudah berupa kalimat (milik service/aturan khusus) tidak disentuh.
     *
     * @param  array<string, mixed>  $errors
     * @return array<string, mixed>
     */
    private static function humaniseValidationErrors(array $errors): array
    {
        foreach ($errors as $field => $messages) {
            $errors[$field] = is_array($messages)
                ? array_map(fn ($message) => self::humaniseValidationMessage($message), $messages)
                : self::humaniseValidationMessage($messages);
        }

        return $errors;
    }

    private static function humaniseValidationMessage(mixed $message): mixed
    {
        // Kunci mentah: `validation.<rule>` atau `validation.<rule>.<type>` (rule ukuran: string/numeric/array/file).
        if (! is_string($message) || preg_match('/\Avalidation\.([A-Za-z0-9_]+)(?:\.([A-Za-z0-9_]+))?(?:\.[A-Za-z0-9_.*-]+)?\z/', $message, $parts) !== 1) {
            return $message;
        }

        $candidates = isset($parts[2]) ? ["{$parts[1]}.{$parts[2]}", $parts[1], 'generic'] : [$parts[1], 'generic'];

        foreach ($candidates as $candidate) {
            $key = "customer_commerce.validation_rules.{$candidate}";
            $line = __($key);

            // Rule yang punya varian per tipe mengembalikan array saat dicari tanpa tipe: dilewati.
            if (is_string($line) && $line !== $key) {
                return $line;
            }
        }

        return (string) __('customer_commerce.details.validation_failed');
    }

    private static function line(string $key): string
    {
        $full = "customer_commerce.{$key}";
        $line = __($full);

        // Kode tanpa terjemahan tidak boleh membocorkan kunci mentah ke pelanggan.
        return is_string($line) && $line !== $full ? $line : (string) __('customer_commerce.details.request_failed');
    }
}
