<?php

namespace Tests\Feature\CustomerCommerce;

use App\Exceptions\CustomerCommerceException;
use App\Http\Middleware\AssignRequestId;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §3) — amplop galat `application/problem+json`,
 * X-Request-Id, dan Accept-Language untuk /api/v1/customer/* dan
 * /api/v1/storefront/*.
 *
 * Catatan test: klien uji Symfony SELALU mengirim `Accept-Language:
 * en-us,en;q=0.5` bila test tidak mengaturnya, jadi test yang memeriksa
 * teks Indonesia mengirim `Accept-Language: id` secara eksplisit dan test
 * "tanpa header" mengosongkannya.
 */
class CustomerCommerceErrorEnvelopeTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    private const PROBLEM_KEYS = ['type', 'title', 'status', 'detail', 'code', 'request_id'];

    /** Rute uji di bawah awalan pelanggan/etalase, memakai grup middleware `api` yang sama. */
    private function registerProbeRoutes(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(function () {
            Route::get('customer/_probe/boom', fn () => throw new RuntimeException('rahasia internal: SQLSTATE dan jalur berkas'));
            Route::get('storefront/_probe/model', fn () => Product::findOrFail(987654));
            Route::get('customer/_probe/typed', fn () => throw CustomerCommerceException::make('address_limit_reached'));
            Route::get('customer/_probe/typed-errors', fn () => throw CustomerCommerceException::make(
                'cart_invalid', errors: ['items' => [['variant_id' => 1, 'reason' => 'unavailable', 'max_qty' => 0]]],
            ));
            Route::get('customer/_probe/forbidden', fn () => abort(403, 'pesan internal'));
            Route::get('customer/_probe/unavailable', fn () => abort(503, 'pesan internal'));
            Route::get('customer-orders/_probe/typed', fn () => throw CustomerCommerceException::make('address_limit_reached'));
        });
    }

    public function test_a_problem_body_has_exactly_the_contract_fields(): void
    {
        $response = $this->withHeader('Accept-Language', 'id')->getJson('/api/v1/customer/me');

        $response->assertStatus(401)->assertHeader('Content-Type', 'application/problem+json');

        $body = $response->json();
        $this->assertSame(self::PROBLEM_KEYS, array_keys($body));
        $this->assertSame('https://boothpos.local/problems/unauthenticated', $body['type']);
        $this->assertSame(401, $body['status']);
        $this->assertSame('unauthenticated', $body['code']);
        $this->assertSame('Sesi tidak valid', $body['title']);
        $this->assertSame('Sesi Anda tidak valid atau sudah berakhir. Silakan masuk kembali.', $body['detail']);
        $this->assertTrue(Str::isUuid($body['request_id']));
        $this->assertSame($body['request_id'], $response->headers->get('X-Request-Id'));
    }

    public function test_a_well_formed_inbound_request_id_is_echoed(): void
    {
        foreach (['abcDEF12', 'app-7f3a.2026_11_11-0001', str_repeat('a', 64)] as $id) {
            $error = $this->withHeader('X-Request-Id', $id)->getJson('/api/v1/customer/me');
            $this->assertSame($id, $error->headers->get('X-Request-Id'));
            $this->assertSame($id, $error->json('request_id'));

            $ok = $this->withHeader('X-Request-Id', $id)->getJson('/api/v1/storefront/config')->assertOk();
            $this->assertSame($id, $ok->headers->get('X-Request-Id'));
        }
    }

    public function test_a_malformed_inbound_request_id_is_replaced_by_a_generated_one(): void
    {
        $bad = ['pendek', str_repeat('a', 65), 'ada spasi 12345', 'garis/miring-12345', "baris\nbaru-12345", '<script>12345678'];

        foreach ($bad as $id) {
            $response = $this->withHeader('X-Request-Id', $id)->getJson('/api/v1/customer/me');

            $echoed = $response->headers->get('X-Request-Id');
            $this->assertNotSame($id, $echoed);
            $this->assertTrue(Str::isUuid($echoed), 'Id pengganti harus UUID.');
            $this->assertSame($echoed, $response->json('request_id'));
        }
    }

    /**
     * CONTRACT §3: id korelasi juga masuk konteks log (Laravel Context ikut di setiap baris log
     * permintaan itu), supaya laporan pelanggan bisa dicocokkan dengan log server tanpa mencatat
     * token atau data pribadi. Rute uji memakai middleware yang sama dengan grup /customer.
     */
    public function test_the_request_id_is_put_into_the_log_context(): void
    {
        Route::middleware(['api', AssignRequestId::class])->prefix('api/v1')->group(function () {
            Route::get('customer/_probe/context', fn () => response()->json(['request_id' => Context::get('request_id')]));
        });

        $echoed = $this->withHeader('X-Request-Id', 'app-ctx-0001-abcd')->getJson('/api/v1/customer/_probe/context')->assertOk();
        $this->assertSame('app-ctx-0001-abcd', $echoed->json('request_id'));

        $generated = $this->withHeader('X-Request-Id', 'ada spasi')->getJson('/api/v1/customer/_probe/context')->assertOk();
        $this->assertTrue(Str::isUuid($generated->json('request_id')));
        $this->assertSame($generated->headers->get('X-Request-Id'), $generated->json('request_id'));
    }

    public function test_each_request_without_an_id_gets_its_own(): void
    {
        $first = $this->getJson('/api/v1/storefront/config')->headers->get('X-Request-Id');
        $second = $this->getJson('/api/v1/storefront/config')->headers->get('X-Request-Id');

        $this->assertTrue(Str::isUuid($first));
        $this->assertTrue(Str::isUuid($second));
        $this->assertNotSame($first, $second);
    }

    public function test_messages_follow_accept_language_with_indonesian_as_the_default(): void
    {
        $id = 'Sesi Anda tidak valid atau sudah berakhir. Silakan masuk kembali.';
        $en = 'Your session is invalid or has expired. Please sign in again.';

        $detail = fn (array $server) => $this->withServerVariables($server)->getJson('/api/v1/customer/me')->assertStatus(401)->json('detail');

        $this->assertSame($id, $detail(['HTTP_ACCEPT_LANGUAGE' => '']), 'Tanpa header: Indonesia.');
        $this->assertSame($id, $detail(['HTTP_ACCEPT_LANGUAGE' => 'id-ID,id;q=0.9']));
        $this->assertSame($en, $detail(['HTTP_ACCEPT_LANGUAGE' => 'en']));
        $this->assertSame($en, $detail(['HTTP_ACCEPT_LANGUAGE' => 'en-GB,en;q=0.8,id;q=0.5']));
        $this->assertSame($id, $detail(['HTTP_ACCEPT_LANGUAGE' => 'id,en;q=0.5']));
        // Bahasa di luar daftar izin tidak pernah dipakai sebagai locale.
        $this->assertSame($id, $detail(['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9']));
        $this->assertSame($id, $detail(['HTTP_ACCEPT_LANGUAGE' => '../../etc/passwd']));
    }

    public function test_validation_errors_are_localised_and_keep_the_errors_map(): void
    {
        $response = $this->withHeader('Accept-Language', 'id')->postJson('/api/v1/customer/auth/login', []);

        $response->assertStatus(422)->assertHeader('Content-Type', 'application/problem+json');
        $this->assertSame(['type', 'title', 'status', 'detail', 'code', 'errors', 'request_id'], array_keys($response->json()));
        $response->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('status', 422)
            ->assertJsonPath('detail', 'Data yang dikirim tidak valid. Periksa kembali isian Anda.');
        $this->assertIsString($response->json('errors.email.0'));
        $this->assertIsString($response->json('errors.password.0'));
    }

    public function test_unknown_routes_under_both_prefixes_answer_a_generic_404(): void
    {
        foreach (['/api/v1/customer/tidak-ada', '/api/v1/storefront/tidak-ada', '/api/v1/customer', '/api/v1/storefront'] as $uri) {
            $response = $this->withHeader('Accept-Language', 'id')->getJson($uri);

            $response->assertStatus(404)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('code', 'not_found')
                ->assertJsonPath('detail', 'Data yang Anda cari tidak ditemukan.');
            $this->assertSame(self::PROBLEM_KEYS, array_keys($response->json()));
            $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-Id'));
            $this->assertStringNotContainsString('route', $response->getContent());
        }
    }

    public function test_a_missing_model_never_names_its_class(): void
    {
        $this->registerProbeRoutes();

        $response = $this->getJson('/api/v1/storefront/_probe/model');

        $response->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->assertSame(self::PROBLEM_KEYS, array_keys($response->json()));
        $this->assertStringNotContainsString('Product', $response->getContent());
        $this->assertStringNotContainsString('987654', $response->getContent());
        $this->assertStringNotContainsString('Models', $response->getContent());
    }

    public function test_a_wrong_method_answers_405_and_keeps_the_allow_header(): void
    {
        $response = $this->getJson('/api/v1/customer/auth/login');

        $response->assertStatus(405)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'method_not_allowed');
        $this->assertSame(self::PROBLEM_KEYS, array_keys($response->json()));
        $this->assertStringContainsString('POST', (string) $response->headers->get('Allow'));
    }

    public function test_an_unexpected_exception_is_a_generic_500_without_internals(): void
    {
        $this->registerProbeRoutes();

        $response = $this->withHeader('Accept-Language', 'id')->getJson('/api/v1/customer/_probe/boom');

        $response->assertStatus(500)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'server_error')
            ->assertJsonPath('detail', 'Terjadi kesalahan di server. Silakan coba lagi beberapa saat lagi.');
        $this->assertSame(self::PROBLEM_KEYS, array_keys($response->json()));

        foreach (['rahasia internal', 'SQLSTATE', 'RuntimeException', 'trace', 'exception', '.php'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }
    }

    public function test_http_errors_keep_their_status_but_never_their_internal_message(): void
    {
        $this->registerProbeRoutes();

        $forbidden = $this->getJson('/api/v1/customer/_probe/forbidden');
        $forbidden->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->assertStringNotContainsString('pesan internal', $forbidden->getContent());

        $unavailable = $this->getJson('/api/v1/customer/_probe/unavailable');
        $unavailable->assertStatus(503)->assertJsonPath('code', 'server_error');
        $this->assertStringNotContainsString('pesan internal', $unavailable->getContent());
    }

    public function test_the_typed_exception_renders_itself_as_a_problem(): void
    {
        $this->registerProbeRoutes();

        $plain = $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/customer/_probe/typed');
        $plain->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'address_limit_reached')
            ->assertJsonPath('type', 'https://boothpos.local/problems/address_limit_reached')
            ->assertJsonPath('status', 409);
        $this->assertSame(self::PROBLEM_KEYS, array_keys($plain->json()));
        $this->assertSame($plain->json('request_id'), $plain->headers->get('X-Request-Id'));

        $withErrors = $this->getJson('/api/v1/customer/_probe/typed-errors');
        $withErrors->assertStatus(409)
            ->assertJsonPath('code', 'cart_invalid')
            ->assertJsonPath('errors.items.0.reason', 'unavailable');
        $this->assertSame(['type', 'title', 'status', 'detail', 'code', 'errors', 'request_id'], array_keys($withErrors->json()));
    }

    public function test_the_typed_exception_uses_the_staff_envelope_outside_the_customer_prefixes(): void
    {
        // `customer-orders` (fulfilment staf) BUKAN awalan pelanggan: bentuk staf `{message, code}`.
        $this->registerProbeRoutes();

        $response = $this->getJson('/api/v1/customer-orders/_probe/typed');

        $response->assertStatus(409)->assertJsonPath('code', 'address_limit_reached');
        $this->assertSame(['message', 'code'], array_keys($response->json()));
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function test_expected_business_failures_are_not_reported_but_unexpected_errors_are(): void
    {
        $this->registerProbeRoutes();
        Exceptions::fake();

        // Login gagal, 401 tanpa token, validasi: bisa dipicu siapa pun, tidak boleh membanjiri log.
        $this->postJson('/api/v1/customer/auth/login', ['email' => 'tidak-ada@example.test', 'password' => 'salah-sekali-123'])->assertStatus(401);
        $this->getJson('/api/v1/customer/me')->assertStatus(401);
        $this->postJson('/api/v1/customer/auth/login', [])->assertStatus(422);
        $this->getJson('/api/v1/customer/_probe/typed')->assertStatus(409);
        Exceptions::assertNothingReported();

        // Galat tak terduga TETAP dilaporkan: amplop generik hanya menyembunyikannya dari klien, bukan dari log.
        $this->getJson('/api/v1/customer/_probe/boom')->assertStatus(500);
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_every_machine_code_has_a_title_and_detail_in_both_languages(): void
    {
        $id = Arr::dot(require lang_path('id/customer_commerce.php'));
        $en = Arr::dot(require lang_path('en/customer_commerce.php'));

        $this->assertSame(array_keys($id), array_keys($en), 'lang/id dan lang/en harus punya kunci yang sama, urutan yang sama.');

        $codes = [
            'unauthenticated', 'invalid_credentials', 'forbidden', 'not_found', 'method_not_allowed',
            'idempotency_key_reused', 'cart_invalid', 'price_changed', 'address_limit_reached',
            'validation_failed', 'registration_failed', 'idempotency_key_required',
            'too_many_requests', 'customer_commerce_disabled', 'server_error', 'request_failed',
            'payload_too_large', 'installation_locked',
        ];

        foreach ($codes as $code) {
            foreach (['id' => $id, 'en' => $en] as $locale => $lines) {
                $this->assertNotEmpty($lines["titles.{$code}"] ?? null, "{$locale}: titles.{$code}");
                $this->assertNotEmpty($lines["details.{$code}"] ?? null, "{$locale}: details.{$code}");
            }
        }

        foreach ($id as $key => $line) {
            $this->assertNotSame($line, $en[$key], "Terjemahan {$key} belum dibedakan.");
        }
    }
}
