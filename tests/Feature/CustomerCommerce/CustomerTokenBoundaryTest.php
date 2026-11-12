<?php

namespace Tests\Feature\CustomerCommerce;

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\SetLocaleFromAcceptLanguage;
use App\Http\Middleware\SetLocaleFromUser;
use App\Models\CashierSession;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\SellerGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §1) — BATAS TOKEN, dua arah, dengan token
 * bearer SUNGGUHAN (bukan actingAs, yang melewati guard sama sekali).
 *
 * Tanpa pengikatan guard→provider di config/auth.php, guard `sanctum`
 * menerima token milik model APA PUN yang memakai HasApiTokens: token
 * pelanggan akan lolos di rute staf (beberapa benar-benar menjawab 2xx —
 * lihat daftar di bawah — sisanya 500). Test ini mengunci 401 yang bersih.
 */
class CustomerTokenBoundaryTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    /**
     * Rute api/v1 yang SENGAJA tanpa guard: lisensi (harus terjangkau sebelum aktivasi), login staf, daftar/masuk
     * pelanggan, dan katalog publik. Rute baru di luar kedua guard menggagalkan
     * test_every_api_route_sits_behind_one_of_the_two_guards_or_is_a_known_public_route sampai diputuskan dengan
     * sadar dan ditambahkan di sini.
     */
    private const PUBLIC_ROUTES = [
        'GET api/v1/license/status',
        'POST api/v1/license/activate',
        'POST api/v1/auth/login',
        'POST api/v1/customer/auth/register',
        'POST api/v1/customer/auth/login',
        'GET api/v1/storefront/config',
        'GET api/v1/storefront/categories',
        'GET api/v1/storefront/products',
        'GET api/v1/storefront/products/{slug}',
    ];

    /** @return list<array{method: string, uri: string, route: LaravelRoute}> setiap pasangan metode+URI di api/v1 */
    private function apiRoutes(): array
    {
        $out = [];
        /** @var LaravelRoute $route */
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $out[] = ['method' => $method, 'uri' => $route->uri(), 'route' => $route];
                }
            }
        }

        return $out;
    }

    /**
     * URI nyata untuk sebuah rute: tiap parameter diisi contoh yang lolos batasan `where`-nya. Tanpa itu permintaan
     * berhenti 404 di router dan tidak membuktikan apa pun tentang guard. Route-model binding tidak berjalan sebelum
     * guard (prioritas middleware: Authenticate sebelum SubstituteBindings), jadi id contoh tidak perlu ada.
     */
    private function concreteUri(LaravelRoute $route): string
    {
        $samples = ['1', 'CO-0000000000', '2026-01-01_000000', 'sales', 'artists', 'contoh-slug'];

        return '/'.preg_replace_callback('/\{(\w+)\??\}/', function (array $match) use ($route, $samples): string {
            $pattern = $route->wheres[$match[1]] ?? null;
            foreach ($samples as $sample) {
                if ($pattern === null || preg_match('#^(?:'.$pattern.')$#', $sample) === 1) {
                    return $sample;
                }
            }
            $this->fail("Tidak ada contoh nilai untuk {{$match[1]}} ({$pattern}) di {$route->uri()}; tambahkan ke \$samples.");
        }, $route->uri());
    }

    public function test_guards_are_bound_to_their_own_provider(): void
    {
        $this->assertSame(['driver' => 'sanctum', 'provider' => 'users'], config('auth.guards.sanctum'));
        $this->assertSame(['driver' => 'sanctum', 'provider' => 'customer_accounts'], config('auth.guards.customer'));
        $this->assertSame(CustomerAccount::class, config('auth.providers.customer_accounts.model'));
        $this->assertSame(User::class, config('auth.providers.users.model'));
        $this->assertNull(config('sanctum.expiration'), 'Umur token staf tidak berubah: kedaluwarsa hanya per token pelanggan.');
    }

    public function test_a_customer_token_is_refused_on_every_staff_route(): void
    {
        Storage::fake('local');

        // Staf dengan id numerik yang SAMA dengan akun pelanggan dan sesi kasir
        // terbuka: tanpa batas guard, GET /sessions/current membocorkan sesi ini
        // dan POST /payment-proofs menulis bukti atas nama staf tersebut.
        $staff = User::factory()->create(['role' => 'owner']);
        [$account, $token] = $this->customerWithToken(['id' => $staff->id]);
        $this->assertSame($staff->id, $account->id, 'Skenario tabrakan id: users.id == customer_accounts.id.');
        CashierSession::factory()->create(['user_id' => $staff->id, 'status' => 'open']);
        $passwordBefore = DB::table('customer_accounts')->where('id', $account->id)->value('password');

        $attempts = [
            ['POST', '/auth/logout', []],
            ['GET', '/auth/me', []],
            ['GET', '/sessions/current', []],
            ['GET', '/catalog', []],
            ['GET', '/products', []],
            ['POST', '/orders', ['items' => []]],
            ['POST', '/payment-proofs', ['file' => UploadedFile::fake()->create('bukti.jpg', 10, 'image/jpeg'), 'captured_via' => 'upload']],
            ['PUT', '/auth/password', ['current_password' => 'password', 'password' => 'kata-sandi-baru-1', 'password_confirmation' => 'kata-sandi-baru-1']],
            ['GET', '/settings/features', []],
            ['GET', '/customers', []],
            ['GET', '/settings', []],
            ['PUT', '/settings', ['settings' => [['key' => 'customer_commerce_enabled', 'value' => true]]]],
        ];

        foreach ($attempts as [$method, $uri, $payload]) {
            $response = $this->bearer($token)->json($method, "/api/v1{$uri}", $payload);

            $this->assertSame(401, $response->status(), "{$method} {$uri} harus 401 untuk token pelanggan, dapat {$response->status()}.");
            // Bentuk galat staf tidak berubah: tanpa amplop problem+json.
            $response->assertExactJson(['message' => 'Unauthenticated.']);
        }

        // Tidak ada efek samping dari percobaan di atas.
        $this->assertSame(1, $account->tokens()->count(), 'POST /auth/logout staf tidak boleh mencabut token pelanggan.');
        $this->assertSame(0, DB::table('payment_proofs')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame($passwordBefore, DB::table('customer_accounts')->where('id', $account->id)->value('password'));
        $this->assertTrue(Hash::check('password', DB::table('users')->where('id', $staff->id)->value('password')));
        $this->assertSame(0, DB::table('settings')->where('key', 'customer_commerce_enabled')->count());

        // Token yang sama tetap sah di sisi pelanggan.
        $this->bearer($token)->getJson('/api/v1/customer/me')->assertOk();
    }

    public function test_a_staff_token_is_refused_on_every_customer_route(): void
    {
        $staff = User::factory()->create(['role' => 'owner']);
        $token = $this->issueStaffToken($staff);
        $account = $this->makeCustomerAccount(['id' => $staff->id], ['name' => 'Nama Asli']);
        $this->assertSame($staff->id, $account->id, 'Skenario tabrakan id: users.id == customer_accounts.id.');

        $attempts = [
            ['GET', '/customer/me', []],
            ['PUT', '/customer/me', ['name' => 'Diubah Staf']],
            ['POST', '/customer/auth/logout', []],
        ];

        foreach ($attempts as [$method, $uri, $payload]) {
            $this->bearer($token)->json($method, "/api/v1{$uri}", $payload)
                ->assertStatus(401)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('code', 'unauthenticated');
        }

        $this->assertSame(1, $staff->tokens()->count(), 'Logout pelanggan tidak boleh mencabut token staf.');
        $this->assertDatabaseHas('customers', ['id' => $account->customer_id, 'name' => 'Nama Asli']);

        // Token staf yang sama tetap sah di rute staf.
        $this->bearer($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('username', $staff->username);
    }

    public function test_a_staff_session_or_forced_guard_user_is_refused_on_customer_routes(): void
    {
        $staff = User::factory()->create(['role' => 'owner']);
        $this->makeCustomerAccount();

        // Jalur guard `web` (sesi): Sanctum mengembalikannya SEBELUM memeriksa
        // token/provider. EnsureCustomerAccount yang menutup celah ini.
        $this->actingAs($staff)->getJson('/api/v1/customer/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');

        // Bahkan bila seorang User dipaksa menjadi pengguna guard `customer`.
        Auth::forgetGuards();
        $this->actingAs($staff, 'customer')->getJson('/api/v1/customer/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->actingAs($staff, 'customer')->putJson('/api/v1/customer/me', ['name' => 'X'])->assertStatus(401);
    }

    public function test_the_seller_gate_ignores_a_customer_principal(): void
    {
        $world = $this->buildStorefrontWorld();
        $account = $this->makeCustomerAccount();

        Auth::shouldUse('customer');
        Auth::guard('customer')->setUser($account);

        $this->assertInstanceOf(CustomerAccount::class, auth()->user());
        $this->assertNull(SellerGate::current());
        $this->assertFalse(SellerGate::active());

        // Model ber-SellerScope bisa dibaca tanpa TypeError; pelanggan bukan pengguna ber-seller.
        $this->assertSame(2, Product::count());
        $this->assertTrue(ProductVariant::whereKey($world['variantA']->id)->exists());
        $this->assertSame($account->customer_id, Customer::findOrFail($account->customer_id)->id);
        $this->assertSame($account->customer_id, $account->fresh()->customer->id);
    }

    public function test_the_seller_gate_still_narrows_a_seller_bound_staff_user(): void
    {
        $world = $this->buildStorefrontWorld();
        $seller = User::factory()->create(['role' => 'cashier', 'artist_id' => $world['artist']->id]);

        $this->actingAs($seller, 'sanctum');

        $this->assertSame($world['artist']->id, SellerGate::current());
        $this->assertSame($world['artist']->id, SellerGate::forUser($seller));
    }

    public function test_every_api_route_sits_behind_one_of_the_two_guards_or_is_a_known_public_route(): void
    {
        $unguarded = [];
        foreach ($this->apiRoutes() as ['method' => $method, 'uri' => $uri, 'route' => $route]) {
            $middleware = $route->gatherMiddleware();
            $staff = in_array('auth:sanctum', $middleware, true);
            $customer = in_array('auth:customer', $middleware, true);
            $key = "{$method} {$uri}";

            $this->assertFalse($staff && $customer, "{$key} memakai dua guard sekaligus.");
            if (! $staff && ! $customer) {
                $unguarded[] = $key;
            }

            // Awalan URI menentukan guard: guard `customer` hanya di /customer, dan rute pelanggan/etalase tidak pernah
            // memakai guard staf (token staf di sana = 401, bukan akses).
            if ($customer) {
                $this->assertStringStartsWith('api/v1/customer/', $uri, "{$key}: guard `customer` di luar /customer.");
            }
            if (preg_match('#^api/v1/(customer|storefront)(/|$)#', $uri) === 1) {
                $this->assertFalse($staff, "{$key}: rute pelanggan/etalase memakai guard staf.");
            }
        }

        $expected = self::PUBLIC_ROUTES;
        sort($expected);
        sort($unguarded);
        $this->assertSame($expected, $unguarded, 'Rute api/v1 di luar kedua guard berubah: putuskan dengan sadar, lalu perbarui PUBLIC_ROUTES.');
    }

    public function test_every_route_behind_the_staff_guard_refuses_a_customer_token(): void
    {
        // Menyapu SELURUH tabel rute, bukan contoh: rute staf yang ditambahkan kelak ikut terbukti menolak token
        // pelanggan, dengan bentuk galat staf yang tidak berubah.
        [$account, $token] = $this->customerWithToken();
        $checked = [];

        foreach ($this->apiRoutes() as ['method' => $method, 'uri' => $uri, 'route' => $route]) {
            if (! in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                continue;
            }

            $response = $this->bearer($token)->json($method, $this->concreteUri($route));

            $this->assertSame(401, $response->status(), "{$method} {$uri} harus 401 untuk token pelanggan, dapat {$response->status()}.");
            $this->assertSame(['message' => 'Unauthenticated.'], $response->json(), "{$method} {$uri}: bentuk galat staf berubah.");
            $checked[] = "{$method} {$uri}";
        }

        // Pengaman: loop benar-benar melewati API staf, termasuk rute fulfilment dan publikasi fitur ini serta POS.
        $this->assertGreaterThan(200, count($checked));
        foreach (['GET api/v1/customer-orders', 'POST api/v1/customer-orders/{customerOrder}/confirm', 'PATCH api/v1/customer-orders/{customerOrder}/shipment', 'POST api/v1/products/{product}/publish', 'POST api/v1/orders', 'GET api/v1/catalog'] as $key) {
            $this->assertContains($key, $checked);
        }
        $this->assertSame(1, $account->tokens()->count(), 'Percobaan di rute staf tidak boleh mencabut token pelanggan.');
    }

    public function test_every_route_behind_the_customer_guard_refuses_a_staff_token(): void
    {
        // Saklar MENYALA supaya 401 tidak tertutup 503: guard yang menolak, bukan saklar fitur.
        $this->enableCustomerCommerce();
        $staff = User::factory()->create(['role' => 'owner']);
        $token = $this->issueStaffToken($staff);
        $checked = [];

        foreach ($this->apiRoutes() as ['method' => $method, 'uri' => $uri, 'route' => $route]) {
            if (! in_array('auth:customer', $route->gatherMiddleware(), true)) {
                continue;
            }

            $response = $this->bearer($token)->json($method, $this->concreteUri($route));

            $this->assertSame(401, $response->status(), "{$method} {$uri} harus 401 untuk token staf, dapat {$response->status()}.");
            $this->assertSame('application/problem+json', $response->headers->get('Content-Type'), "{$method} {$uri}");
            $this->assertSame('unauthenticated', $response->json('code'), "{$method} {$uri}");
            $checked[] = "{$method} {$uri}";
        }

        foreach (['GET api/v1/customer/me', 'PUT api/v1/customer/me', 'POST api/v1/customer/auth/logout', 'POST api/v1/customer/addresses', 'DELETE api/v1/customer/addresses/{addressId}', 'POST api/v1/customer/orders/quote', 'POST api/v1/customer/orders', 'GET api/v1/customer/orders/{orderNumber}/tracking'] as $key) {
            $this->assertContains($key, $checked);
        }
        $this->assertSame(1, $staff->tokens()->count(), 'Logout pelanggan tidak boleh mencabut token staf.');
        $this->assertSame(0, DB::table('customer_addresses')->count());
        $this->assertSame(0, DB::table('customer_orders')->count());
    }

    public function test_customer_and_storefront_routes_carry_their_own_middleware_and_none_of_the_staff_ones(): void
    {
        $checked = 0;
        foreach ($this->apiRoutes() as ['method' => $method, 'uri' => $uri, 'route' => $route]) {
            if (preg_match('#^api/v1/(customer|storefront)(/|$)#', $uri) !== 1) {
                continue;
            }
            $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
            $key = "{$method} {$uri}";

            // CONTRACT §3: id permintaan dan bahasa dari Accept-Language di kedua grup.
            $this->assertContains(AssignRequestId::class, $middleware, "{$key}: tanpa X-Request-Id.");
            $this->assertContains(SetLocaleFromAcceptLanguage::class, $middleware, "{$key}: bahasa tidak mengikuti Accept-Language.");
            // CONTRACT §1: tidak ada middleware staf. SetLocaleFromUser membaca kolom `users.language`; alias `ability`
            // adalah gerbang menu staf; `seller.forbidden` dan `auth:sanctum` milik grup staf.
            $this->assertNotContains(SetLocaleFromUser::class, $middleware, "{$key}: SetLocaleFromUser di rute pelanggan.");
            $this->assertNotContains('seller.forbidden', $middleware, $key);
            $this->assertNotContains('auth:sanctum', $middleware, $key);
            $this->assertSame([], array_values(array_filter($middleware, fn (string $m) => str_starts_with($m, 'ability:'))), "{$key}: gerbang menu staf di rute pelanggan.");

            // Limiter BERNAMA saja: `throttle:N,M` tanpa nama berbagi ember dengan login staf per IP.
            $throttles = array_values(array_filter($middleware, fn (string $m) => str_starts_with($m, 'throttle:')));
            $this->assertNotSame([], $throttles, "{$key}: tanpa limiter.");
            foreach ($throttles as $throttle) {
                $this->assertMatchesRegularExpression('/^throttle:(storefront|customer-[a-z]+)$/', $throttle, $key);
            }

            // Setiap rute ber-guard pelanggan juga memeriksa akunnya (nonaktif, ability token, kontak hilang).
            if (in_array('auth:customer', $middleware, true)) {
                $this->assertContains('customer.account', $middleware, "{$key}: tanpa EnsureCustomerAccount.");
            }
            $checked++;
        }

        $this->assertGreaterThanOrEqual(19, $checked);
    }
}
