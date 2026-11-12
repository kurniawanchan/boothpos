<?php

namespace Tests\Feature\CustomerCommerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §3) — galat validasi 422 untuk pelanggan harus
 * berupa KALIMAT, bukan kunci terjemahan mentah.
 *
 * Proyek ini tidak punya teks validasi (lang/{id,en}/validation.php kosong),
 * jadi Laravel mengembalikan kuncinya ("validation.in") sebagai pesan. Aplikasi
 * Android menampilkan pesan per-field itu apa adanya, jadi CustomerCommerceProblem
 * menerjemahkannya KHUSUS untuk rute pelanggan/etalase. Galat staf tidak boleh
 * berubah satu byte pun (SPA staf dan aplikasi POS membacanya).
 */
class CustomerValidationMessagesTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    /** Satu akun per test (pembuatan akun + token mahal), dipakai ulang untuk kedua bahasa. */
    private ?string $token = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCustomerCommerce();
        $this->token = null;
    }

    /**
     * Permintaan tidak sah yang SENGAJA melewati banyak jenis aturan: required,
     * string, max.string, min.string/numeric, email, in, integer, array, list,
     * distinct, boolean, regex, required_if. Tiap item: [metode, uri, badan, perlu token].
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: bool}>
     */
    public static function invalidRequests(): array
    {
        $longName = str_repeat('a', 101);

        return [
            'register kosong' => ['POST', '/api/v1/customer/auth/register', [], false],
            'register salah bentuk' => ['POST', '/api/v1/customer/auth/register', [
                'name' => ['bukan', 'string'], 'email' => 'bukan-email', 'password' => 'pendek', 'phone' => str_repeat('9', 31),
            ], false],
            'register nama kepanjangan' => ['POST', '/api/v1/customer/auth/register', [
                'name' => $longName, 'email' => 'a@example.test', 'password' => 'kata-sandi-uji-123',
            ], false],
            'login kosong' => ['POST', '/api/v1/customer/auth/login', [], false],
            'login sandi kepanjangan' => ['POST', '/api/v1/customer/auth/login', [
                'email' => 'a@example.test', 'password' => str_repeat('p', 73),
            ], false],
            'profil kosong' => ['PUT', '/api/v1/customer/me', [], true],
            'profil nama kepanjangan' => ['PUT', '/api/v1/customer/me', ['name' => $longName], true],
            'alamat kosong' => ['POST', '/api/v1/customer/addresses', [], true],
            'alamat boolean salah' => ['POST', '/api/v1/customer/addresses', [
                'recipient_name' => 'Rina', 'phone' => '0812', 'address_line' => 'Jl. Uji 1', 'city' => 'Bandung',
                'province' => 'Jawa Barat', 'is_default' => 'mungkin',
            ], true],
            'quote kosong' => ['POST', '/api/v1/customer/orders/quote', [], true],
            'quote metode salah' => ['POST', '/api/v1/customer/orders/quote', [
                'fulfilment_method' => 'drone', 'items' => [['variant_id' => 1, 'qty' => 1]],
            ], true],
            'quote baris salah' => ['POST', '/api/v1/customer/orders/quote', [
                'fulfilment_method' => 'pickup',
                'items' => [['variant_id' => 'x', 'qty' => 0], ['variant_id' => 5, 'qty' => 1], ['variant_id' => 5, 'qty' => 1]],
            ], true],
            'quote items bukan daftar' => ['POST', '/api/v1/customer/orders/quote', [
                'fulfilment_method' => 'pickup', 'items' => ['a' => ['variant_id' => 1, 'qty' => 1]],
            ], true],
            'quote kurir tanpa alamat' => ['POST', '/api/v1/customer/orders/quote', [
                'fulfilment_method' => 'courier', 'items' => [['variant_id' => 1, 'qty' => 1]],
            ], true],
            'quote items kebanyakan' => ['POST', '/api/v1/customer/orders/quote', [
                'fulfilment_method' => 'pickup',
                'items' => array_map(fn (int $i) => ['variant_id' => $i, 'qty' => 1], range(1, 60)),
            ], true],
            'pesanan total salah bentuk' => ['POST', '/api/v1/customer/orders', [
                'fulfilment_method' => 'pickup', 'items' => [['variant_id' => 1, 'qty' => 1]], 'expected_total' => 'abc',
            ], true],
            'daftar pesanan halaman salah' => ['GET', '/api/v1/customer/orders?page=0&per_page=abc', [], true],
            'etalase parameter salah' => ['GET', '/api/v1/storefront/products?page=abc&per_page=0&sort=zzz&category_id=0&q='.str_repeat('q', 101), [], false],
        ];
    }

    private function send(string $method, string $uri, array $body, bool $auth, string $locale): TestResponse
    {
        $this->withHeader('Accept-Language', $locale);

        if ($auth) {
            $this->token ??= $this->customerWithToken()[1];
            $this->bearer($this->token);
        }

        // Header protokol diperiksa sebelum badan: tanpa ini POST /orders selalu 422 `idempotency_key_required`.
        if ($method === 'POST' && $uri === '/api/v1/customer/orders') {
            $this->withHeader('Idempotency-Key', $this->newIdempotencyKey());
        }

        return $this->json($method, $uri, $body);
    }

    /** @return list<string> */
    private function allMessages(array $errors): array
    {
        $messages = [];
        array_walk_recursive($errors, function ($message) use (&$messages) {
            $messages[] = (string) $message;
        });

        return $messages;
    }

    #[DataProvider('invalidRequests')]
    public function test_no_customer_validation_message_is_a_raw_translation_key(string $method, string $uri, array $body, bool $auth): void
    {
        foreach (['id', 'en'] as $locale) {
            $response = $this->send($method, $uri, $body, $auth, $locale);

            $response->assertStatus(422)->assertJsonPath('code', 'validation_failed');

            $messages = $this->allMessages($response->json('errors'));
            $this->assertNotEmpty($messages, "[{$locale}] 422 tanpa pesan per-field.");

            foreach ($messages as $message) {
                $this->assertStringStartsNotWith('validation.', $message, "[{$locale}] kunci mentah bocor: {$message}");
                $this->assertMatchesRegularExpression('/\s/', $message, "[{$locale}] pesan bukan kalimat: {$message}");
            }

            // Bahasa Indonesia (bawaan) tidak punya teks framework sama sekali: SEMUA kalimatnya milik kita,
            // jadi tanpa nama atribut/field internal. (Bahasa Inggris memakai teks bawaan framework, yang
            // menyebut nama atribut; itu di luar yang diubah di sini.)
            if ($locale === 'id') {
                foreach ($messages as $message) {
                    $this->assertDoesNotMatchRegularExpression('/\b(items|variant_id|expected_total|fulfilment_method|address_id|qty)\b/', $message, "Nama field bocor: {$message}");
                    $this->assertStringNotContainsString(':attribute', $message);
                }
            }
        }
    }

    /** Rute uji yang melempar kunci mentah, memakai grup `api` yang sama dengan rute pelanggan. */
    private function registerRawKeyProbe(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(function () {
            Route::get('customer/_probe/raw-keys', fn () => throw ValidationException::withMessages([
                'pilihan' => ['validation.in'],
                'nama' => ['validation.max.string', 'validation.aturan_yang_tidak_dikenal'],
                'kalimat' => ['Kalimat tulisan tangan milik service.'],
            ]));
        });
    }

    public function test_raw_keys_become_sentences_in_both_languages_and_unknown_rules_fall_back_to_a_generic_one(): void
    {
        $this->registerRawKeyProbe();

        $id = $this->withHeader('Accept-Language', 'id')->getJson('/api/v1/customer/_probe/raw-keys')->assertStatus(422);
        $en = $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/customer/_probe/raw-keys')->assertStatus(422);

        $this->assertSame([
            'pilihan' => ['Pilihan yang dipilih tidak valid.'],
            'nama' => ['Teks terlalu panjang.', 'Nilai ini tidak valid.'],
            'kalimat' => ['Kalimat tulisan tangan milik service.'],
        ], $id->json('errors'));

        $this->assertSame([
            'pilihan' => ['The selected value is not valid.'],
            'nama' => ['This text is too long.', 'This value is not valid.'],
            'kalimat' => ['Kalimat tulisan tangan milik service.'],
        ], $en->json('errors'));
    }

    public function test_the_default_language_is_indonesian_and_key_parity_holds(): void
    {
        $id = $this->send('POST', '/api/v1/customer/auth/register', [], false, 'id');
        $this->assertSame(['Wajib diisi.'], $id->json('errors.email'));

        // Kunci lang id dan en harus sama persis (juga untuk `validation_rules`, termasuk yang bersarang).
        $idRules = require lang_path('id/customer_commerce.php');
        $enRules = require lang_path('en/customer_commerce.php');
        $this->assertSame(array_keys(Arr::dot($idRules['validation_rules'])), array_keys(Arr::dot($enRules['validation_rules'])));

        foreach (['required', 'string', 'max', 'min', 'between', 'email', 'in', 'integer', 'numeric', 'array', 'boolean', 'regex', 'distinct', 'exists', 'unique', 'date', 'digits', 'size', 'generic'] as $rule) {
            $this->assertArrayHasKey($rule, $idRules['validation_rules'], "Rule {$rule} belum punya kalimat.");
        }
    }

    public function test_a_translated_custom_message_is_left_untouched(): void
    {
        // Pesan bertulisan tangan milik service/aturan (bukan kunci mentah) tidak boleh disentuh penerjemah.
        [, $token] = $this->customerWithToken();

        $response = $this->withHeader('Accept-Language', 'id')->bearer($token)->postJson('/api/v1/customer/orders/quote', [
            'fulfilment_method' => 'courier', 'address_id' => 999999,
            'items' => [['variant_id' => 1, 'qty' => 1]],
        ]);

        $response->assertStatus(422);
        $this->assertSame(['Alamat pengiriman tidak valid. Pilih salah satu alamat tersimpan Anda.'], $response->json('errors.address_id'));
    }

    public function test_the_staff_422_body_is_unchanged(): void
    {
        // Rute staf TIDAK diterjemahkan: bentuk dan isi lama (kunci mentah, tanpa kunci problem+json) dikunci.
        $response = $this->postJson('/api/v1/auth/login', []);

        $response->assertStatus(422)->assertExactJson([
            'message' => 'validation.required (and 1 more error)',
            'errors' => [
                'username' => ['validation.required'],
                'password' => ['validation.required'],
            ],
        ]);
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }
}
