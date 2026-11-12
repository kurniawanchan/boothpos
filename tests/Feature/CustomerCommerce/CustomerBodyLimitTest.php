<?php

namespace Tests\Feature\CustomerCommerce;

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\LimitCustomerRequestBody;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §3) — badan permintaan yang kelewat besar
 * ditolak 413 `payload_too_large` sebelum diurai (middleware `customer.body`),
 * dan daftar `items` yang kelewat banyak dipotong sebelum validasi `distinct`
 * (kuadratik) sehingga tetap 422 yang murah.
 */
class CustomerBodyLimitTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    private const PROBLEM_KEYS = ['type', 'title', 'status', 'detail', 'code', 'request_id'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCustomerCommerce();
    }

    /** JSON login yang panjangnya persis $bytes byte (kolom `pad` hanya pengisi). */
    private function bodyOfExactly(int $bytes): string
    {
        $base = json_encode(['email' => 'a@example.test', 'password' => 'x', 'pad' => '']);
        $this->assertLessThan($bytes, strlen($base));

        return json_encode(['email' => 'a@example.test', 'password' => 'x', 'pad' => str_repeat('p', $bytes - strlen($base))]);
    }

    private function postRaw(string $uri, string $json, array $headers = [])
    {
        return $this->call('POST', $uri, [], [], [], $this->transformHeadersToServerVars($headers + [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ]), $json);
    }

    private function assertPayloadTooLarge($response): void
    {
        $response->assertStatus(413)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'payload_too_large')
            ->assertJsonPath('status', 413)
            ->assertJsonPath('type', 'https://boothpos.local/problems/payload_too_large')
            ->assertJsonMissingPath('errors');

        $this->assertSame(self::PROBLEM_KEYS, array_keys($response->json()));
        $this->assertTrue(Str::isUuid($response->json('request_id')));
        $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-Id'));
    }

    public function test_an_oversized_body_is_refused_with_413_on_every_customer_entry_point(): void
    {
        $big = $this->bodyOfExactly((int) config('customer_commerce.max_body_bytes') + 1);

        foreach (['/api/v1/customer/auth/login', '/api/v1/customer/auth/register', '/api/v1/customer/orders/quote', '/api/v1/customer/orders'] as $uri) {
            $this->assertPayloadTooLarge($this->postRaw($uri, $big));
        }
    }

    public function test_the_limit_is_exclusive_a_body_of_exactly_the_limit_still_goes_through(): void
    {
        $exact = $this->bodyOfExactly((int) config('customer_commerce.max_body_bytes'));

        // Lolos penjaga ukuran dan sampai ke logika login: e-mail tidak dikenal = 401 generik.
        $this->postRaw('/api/v1/customer/auth/login', $exact)->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_a_declared_content_length_over_the_limit_is_refused_without_reading_the_body(): void
    {
        $declared = (string) ((int) config('customer_commerce.max_body_bytes') + 1);

        // Badan aslinya kecil: yang dipercaya adalah header (klien jujur tidak pernah berbohong soal ini).
        $this->assertPayloadTooLarge($this->postRaw('/api/v1/customer/auth/login', '{}', ['Content-Length' => $declared]));
        // Juga untuk GET (etalase publik dan rute bertoken). `json()` milik test selalu menimpa CONTENT_LENGTH
        // dengan ukuran badan aslinya, jadi header dikirim lewat call() agar yang diuji benar-benar header klien.
        foreach (['/api/v1/storefront/config', '/api/v1/customer/me'] as $uri) {
            $this->assertPayloadTooLarge($this->call('GET', $uri, [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_LENGTH' => $declared]));
        }
    }

    public function test_the_message_follows_accept_language(): void
    {
        $big = $this->bodyOfExactly((int) config('customer_commerce.max_body_bytes') + 1);

        $id = $this->postRaw('/api/v1/customer/auth/login', $big, ['Accept-Language' => 'id']);
        $en = $this->postRaw('/api/v1/customer/auth/login', $big, ['Accept-Language' => 'en']);

        $this->assertSame('Permintaan terlalu besar. Kirim data yang lebih sedikit.', $id->json('detail'));
        $this->assertSame('The request is too large. Please send less data.', $en->json('detail'));
        $this->assertSame('Permintaan terlalu besar', $id->json('title'));
        $this->assertSame('Request too large', $en->json('title'));
    }

    public function test_the_limit_comes_from_config(): void
    {
        config(['customer_commerce.max_body_bytes' => 100]);

        $this->assertPayloadTooLarge($this->postRaw('/api/v1/customer/auth/login', $this->bodyOfExactly(101)));
        $this->postRaw('/api/v1/customer/auth/login', $this->bodyOfExactly(100))->assertStatus(401);
    }

    public function test_a_refused_body_does_not_use_up_the_rate_limit_buckets(): void
    {
        $big = $this->bodyOfExactly((int) config('customer_commerce.max_body_bytes') + 1);
        $perIp = (int) config('customer_commerce.rate_limits.login.per_ip');

        // Lebih banyak daripada jatah per IP: bila penjaga ukuran berjalan SESUDAH limiter, sebagian menjadi 429.
        for ($i = 0; $i < $perIp + 3; $i++) {
            $this->assertPayloadTooLarge($this->postRaw('/api/v1/customer/auth/login', $big));
        }

        $this->postRaw('/api/v1/customer/auth/login', json_encode(['email' => 'a@example.test', 'password' => 'salah-sekali-123']))
            ->assertStatus(401);
    }

    public function test_every_customer_and_storefront_route_runs_the_body_guard_before_the_throttles(): void
    {
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            // `customer-orders` adalah rute STAF (awalan berbeda) dan tidak termasuk.
            if (! preg_match('#\Aapi/v1/(customer|storefront)(/|\z)#', $uri)) {
                continue;
            }

            // Urutan EFEKTIF (alias sudah diurai dan daftar prioritas framework sudah diterapkan): yang
            // dibandingkan di sini adalah yang benar-benar berjalan, bukan urutan penulisan di rute.
            $middleware = array_map(
                fn ($name) => is_string($name) ? Str::before($name, ':') : null,
                app('router')->gatherRouteMiddleware($route),
            );
            $guard = array_search(LimitCustomerRequestBody::class, $middleware, true);

            $this->assertNotFalse($guard, "{$uri} tidak memasang customer.body.");

            foreach ($middleware as $index => $name) {
                if (in_array($name, [Authenticate::class, ThrottleRequests::class], true)) {
                    $this->assertLessThan($index, $guard, "{$uri}: customer.body harus berjalan sebelum {$name}.");
                }
            }

            $this->assertNotFalse(array_search(AssignRequestId::class, $middleware, true), "{$uri} tidak memasang AssignRequestId.");

            $checked++;
        }

        $this->assertGreaterThan(10, $checked);
    }

    public function test_staff_routes_do_not_get_the_customer_body_guard(): void
    {
        $big = $this->bodyOfExactly((int) config('customer_commerce.max_body_bytes') + 1);

        $response = $this->postRaw('/api/v1/auth/login', $big);

        $this->assertNotSame(413, $response->getStatusCode());
        $response->assertJsonMissingPath('code'); // bentuk galat staf, bukan problem+json
    }

    // ------------------------------------------------------------------
    // Daftar `items` yang kelewat banyak dipotong sebelum `distinct`
    // ------------------------------------------------------------------

    /** @return list<int> nomor baris yang disebut galat `items.N.*` */
    private function itemErrorIndexes(array $errors): array
    {
        $indexes = [];

        foreach (array_keys($errors) as $key) {
            if (preg_match('/\Aitems\.(\d+)\./', $key, $match) === 1) {
                $indexes[] = (int) $match[1];
            }
        }

        return $indexes;
    }

    public function test_a_huge_items_list_is_a_bounded_422_instead_of_a_quadratic_distinct_expansion(): void
    {
        // Longgarkan penjaga ukuran supaya yang diuji murni pemotongan di FormRequest.
        config(['customer_commerce.max_body_bytes' => 50_000_000]);
        [, $token] = $this->customerWithToken();
        $max = (int) config('customer_commerce.max_lines_per_order');

        // Semua baris varian yang sama: tanpa pemotongan, `distinct` mengeluh untuk SETIAP baris.
        $items = array_fill(0, 3000, ['variant_id' => 1, 'qty' => 1]);

        $response = $this->bearer($token)->postJson('/api/v1/customer/orders/quote', ['fulfilment_method' => 'pickup', 'items' => $items]);

        $response->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->assertArrayHasKey('items', $response->json('errors'), 'Aturan max daftar harus tetap gagal.');
        $indexes = $this->itemErrorIndexes($response->json('errors'));
        $this->assertNotEmpty($indexes);
        $this->assertLessThanOrEqual($max, max($indexes), 'Galat per baris tidak boleh melewati batas + 1 baris.');

        // Jalur checkout (PlaceOrderRequest) sama.
        $place = $this->bearer($token)->withHeader('Idempotency-Key', $this->newIdempotencyKey())
            ->postJson('/api/v1/customer/orders', ['fulfilment_method' => 'pickup', 'items' => $items, 'expected_total' => '1.00']);

        $place->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->assertArrayHasKey('items', $place->json('errors'));
        $this->assertLessThanOrEqual($max, max($this->itemErrorIndexes($place->json('errors'))));
    }

    public function test_a_list_of_exactly_the_limit_is_not_cut_and_one_more_still_fails_the_max_rule(): void
    {
        [, $token] = $this->customerWithToken();
        $max = (int) config('customer_commerce.max_lines_per_order');
        $lines = fn (int $count) => array_map(fn (int $i) => ['variant_id' => $i + 1, 'qty' => 1], range(0, $count - 1));

        // Tepat batas: lolos bentuk, ditolak keranjang (varian tidak ada) = 409, bukan 422 `max`.
        $this->bearer($token)->postJson('/api/v1/customer/orders/quote', ['fulfilment_method' => 'pickup', 'items' => $lines($max)])
            ->assertStatus(409)->assertJsonPath('code', 'cart_invalid');

        // Satu lebih banyak: 422 pada `items`.
        $over = $this->bearer($token)->postJson('/api/v1/customer/orders/quote', ['fulfilment_method' => 'pickup', 'items' => $lines($max + 1)]);
        $over->assertStatus(422);
        $this->assertArrayHasKey('items', $over->json('errors'));
    }
}
