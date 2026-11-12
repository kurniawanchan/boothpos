<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\CustomerAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §3) — limiter bernama: customer-register,
 * customer-login, storefront, customer-api.
 *
 * Batas dibaca dari config/customer_commerce.php pada saat permintaan, dan
 * kuncinya tidak pernah berbagi ember dengan `throttle:5,1` milik login staf
 * (kalau berbagi, pelanggan di balik satu IP bisa mengunci kasir, dan sebaliknya).
 */
class CustomerAuthThrottleTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    private const PASSWORD = 'password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCustomerCommerce();
    }

    private function assertTooManyRequests($response): void
    {
        $response->assertStatus(429)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'too_many_requests')
            ->assertJsonPath('status', 429)
            ->assertJsonPath('type', 'https://boothpos.local/problems/too_many_requests')
            ->assertJsonMissingPath('errors');

        $this->assertSame(['type', 'title', 'status', 'detail', 'code', 'request_id'], array_keys($response->json()));
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(60, (int) $response->headers->get('Retry-After'));
        $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-Id'));
    }

    private function login(string $email, string $password = 'salah-sekali-123')
    {
        return $this->postJson('/api/v1/customer/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function register(string $email)
    {
        return $this->postJson('/api/v1/customer/auth/register', [
            'name' => 'Pelanggan Uji', 'email' => $email, 'password' => 'kata-sandi-uji-123',
        ]);
    }

    public function test_login_is_limited_per_email(): void
    {
        $limit = (int) config('customer_commerce.rate_limits.login.per_email');
        $this->makeCustomerAccount(['email' => 'rina@example.test']);

        for ($i = 0; $i < $limit; $i++) {
            $this->login('rina@example.test')->assertStatus(401);
        }

        // Permintaan ke-(batas+1) ditolak, juga dengan kata sandi yang BENAR dan ejaan e-mail lain.
        $this->assertTooManyRequests($this->login('rina@example.test'));
        $this->assertTooManyRequests($this->login(' RINA@Example.test ', self::PASSWORD));

        // E-mail lain dari IP yang sama belum terkena (batas per IP lebih longgar).
        $this->login('lain@example.test')->assertStatus(401);
    }

    public function test_login_is_limited_per_ip_across_emails(): void
    {
        $limit = (int) config('customer_commerce.rate_limits.login.per_ip');

        for ($i = 0; $i < $limit; $i++) {
            $this->login("tebakan{$i}@example.test")->assertStatus(401);
        }

        $this->assertTooManyRequests($this->login('tebakan-berikutnya@example.test'));

        // IP lain tidak ikut terkunci.
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.8.7'])->login('tebakan-berikutnya@example.test')->assertStatus(401);
    }

    public function test_register_is_limited_per_ip(): void
    {
        $limit = (int) config('customer_commerce.rate_limits.register.per_ip');

        for ($i = 0; $i < $limit; $i++) {
            $this->register("baru{$i}@example.test")->assertCreated();
        }

        $this->assertTooManyRequests($this->register('baru-berikutnya@example.test'));
        $this->assertSame($limit, CustomerAccount::count());
    }

    public function test_register_is_limited_per_email(): void
    {
        config(['customer_commerce.rate_limits.register.per_ip' => 100]);
        $limit = (int) config('customer_commerce.rate_limits.register.per_email');

        $this->register('rina@example.test')->assertCreated();
        for ($i = 1; $i < $limit; $i++) {
            $this->register('rina@example.test')->assertStatus(422)->assertJsonPath('code', 'registration_failed');
        }

        $this->assertTooManyRequests($this->register('RINA@example.test'));
        $this->register('lain@example.test')->assertCreated();
    }

    public function test_customer_and_staff_login_never_share_a_bucket(): void
    {
        // Habiskan ember login pelanggan untuk IP ini...
        $limit = (int) config('customer_commerce.rate_limits.login.per_ip');
        for ($i = 0; $i <= $limit; $i++) {
            $this->login("tebakan{$i}@example.test");
        }
        $this->login('sekali-lagi@example.test')->assertStatus(429);

        // ...login staf dari IP yang sama tetap dilayani (401 kredensial salah, bukan 429).
        $this->postJson('/api/v1/auth/login', ['username' => 'tidak-ada', 'password' => 'salah-sekali'])->assertStatus(401);
    }

    public function test_an_exhausted_staff_login_bucket_does_not_lock_customers_out(): void
    {
        $this->makeCustomerAccount(['email' => 'rina@example.test']);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/login', ['username' => 'tidak-ada', 'password' => 'salah-sekali']);
        }
        $this->postJson('/api/v1/auth/login', ['username' => 'tidak-ada', 'password' => 'salah-sekali'])->assertStatus(429);

        $this->login('rina@example.test', self::PASSWORD)->assertOk();
    }

    public function test_storefront_routes_are_limited_per_ip(): void
    {
        config(['customer_commerce.rate_limits.storefront.per_ip' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/v1/storefront/config')->assertOk();
        }

        $this->assertTooManyRequests($this->getJson('/api/v1/storefront/config'));
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.8.7'])->getJson('/api/v1/storefront/config')->assertOk();
    }

    public function test_authenticated_customer_routes_are_limited_per_account(): void
    {
        config(['customer_commerce.rate_limits.customer_api.per_account' => 2]);
        [, $mine] = $this->customerWithToken();
        [, $theirs] = $this->customerWithToken();

        $this->bearer($mine)->getJson('/api/v1/customer/me')->assertOk();
        $this->bearer($mine)->getJson('/api/v1/customer/me')->assertOk();
        $this->assertTooManyRequests($this->bearer($mine)->getJson('/api/v1/customer/me'));

        // Akun lain dari IP yang sama punya ember sendiri.
        $this->bearer($theirs)->getJson('/api/v1/customer/me')->assertOk();
    }
}
