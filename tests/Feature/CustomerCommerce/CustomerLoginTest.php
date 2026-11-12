<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\CustomerAccount;
use App\Services\CustomerCommerce\CustomerAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §1, §5) — login, logout dan umur token pelanggan.
 *
 * Semua test di sini memakai token SUNGGUHAN + bearer(): di bawah actingAs()
 * guard tidak benar-benar memeriksa token, kedaluwarsa, maupun ability.
 */
class CustomerLoginTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    /** Sama dengan nilai uji bawaan CustomerAccountFactory. */
    private const PASSWORD = 'password';

    public function test_login_returns_a_token_and_the_account(): void
    {
        Carbon::setTestNow('2026-11-11 10:00:00');
        $account = $this->makeCustomerAccount(['email' => 'rina@example.test'], ['name' => 'Rina Pelanggan', 'phone' => '081200000001']);

        $response = $this->postJson('/api/v1/customer/auth/login', ['email' => 'rina@example.test', 'password' => self::PASSWORD]);

        $response->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('account.id', $account->id)
            ->assertJsonPath('account.name', 'Rina Pelanggan')
            ->assertJsonPath('account.email', 'rina@example.test')
            ->assertJsonPath('account.phone', '081200000001')
            ->assertJsonPath('account.email_verified', true);

        $this->assertSame(['token', 'token_type', 'expires_at', 'account'], array_keys($response->json()));
        $this->assertSame(['id', 'name', 'email', 'phone', 'email_verified', 'created_at'], array_keys($response->json('account')));
        $this->assertTrue(Carbon::parse($response->json('expires_at'))->equalTo(now()->addMinutes(20160)));
        $this->assertTrue($account->fresh()->last_login_at->equalTo(now()));

        $token = $account->tokens()->sole();
        $this->assertSame('customer-app', $token->name);
        $this->assertSame(['customer'], $token->abilities);

        $this->bearer($response->json('token'))->getJson('/api/v1/customer/me')->assertOk()->assertJsonPath('data.id', $account->id);
    }

    public function test_the_email_is_matched_case_insensitively(): void
    {
        $this->makeCustomerAccount(['email' => 'rina@example.test']);

        $this->postJson('/api/v1/customer/auth/login', ['email' => ' RINA@Example.test ', 'password' => self::PASSWORD])->assertOk();
    }

    public function test_unknown_email_wrong_password_and_disabled_account_fail_identically(): void
    {
        $this->makeCustomerAccount(['email' => 'aktif@example.test']);
        $disabled = CustomerAccount::factory()->disabled()->create(['email' => 'nonaktif@example.test']);

        $unknown = $this->postJson('/api/v1/customer/auth/login', ['email' => 'tidak-ada@example.test', 'password' => self::PASSWORD]);
        $wrong = $this->postJson('/api/v1/customer/auth/login', ['email' => 'aktif@example.test', 'password' => 'salah-sekali-123']);
        $off = $this->postJson('/api/v1/customer/auth/login', ['email' => 'nonaktif@example.test', 'password' => self::PASSWORD]);

        $strip = function ($response) {
            $body = $response->json();
            unset($body['request_id']);

            return $body;
        };

        foreach ([$unknown, $wrong, $off] as $response) {
            $response->assertStatus(401)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('code', 'invalid_credentials')
                ->assertJsonMissingPath('errors');
        }

        $this->assertSame($strip($unknown), $strip($wrong));
        $this->assertSame($strip($unknown), $strip($off));

        // Tidak ada token terbit dan login gagal tidak dicap sebagai login.
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertNull($disabled->fresh()->last_login_at);
    }

    public function test_an_unknown_email_still_costs_one_password_hash_like_a_wrong_password(): void
    {
        // CONTRACT §5: e-mail tak dikenal menjalankan hash tiruan, supaya lama respons tidak membedakannya dari
        // kata sandi salah (enumerasi akun lewat waktu). Pencatat meneruskan setiap panggilan ke hasher asli dan
        // hanya menghitung hash atas kata sandi yang DIKIRIM; gerbang lisensi juga memakai Hash::check, tetapi
        // atas sidik jari mesin, jadi tidak ikut terhitung.
        $this->makeCustomerAccount(['email' => 'aktif@example.test']);
        $submitted = 'sandi-uji-penghitung-hash';
        $recorder = new class($this->app->make('hash'), $submitted)
        {
            /** @var list<string> */
            public array $passwordHashes = [];

            public function __construct(private object $real, private string $watched) {}

            public function __call(string $method, array $arguments): mixed
            {
                if (in_array($method, ['make', 'check'], true) && ($arguments[0] ?? null) === $this->watched) {
                    $this->passwordHashes[] = $method;
                }

                return $this->real->{$method}(...$arguments);
            }
        };
        Hash::swap($recorder);

        $this->postJson('/api/v1/customer/auth/login', ['email' => 'tidak-ada@example.test', 'password' => $submitted])
            ->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
        $unknown = $recorder->passwordHashes;

        $recorder->passwordHashes = [];
        $this->postJson('/api/v1/customer/auth/login', ['email' => 'aktif@example.test', 'password' => $submitted])
            ->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
        $wrong = $recorder->passwordHashes;

        $this->assertSame(['make'], $unknown, 'E-mail tak dikenal harus tetap menghitung satu hash atas kata sandi yang dikirim.');
        $this->assertSame(['check'], $wrong);
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/v1/customer/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $account = $this->makeCustomerAccount();
        $phone = $this->issueCustomerToken($account);
        $tablet = $this->issueCustomerToken($account);

        $response = $this->bearer($phone)->postJson('/api/v1/customer/auth/logout');

        $response->assertNoContent();
        $this->assertSame('', $response->getContent());
        $this->assertSame(1, $account->tokens()->count());

        $this->bearer($phone)->getJson('/api/v1/customer/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->bearer($tablet)->getJson('/api/v1/customer/me')->assertOk();
    }

    public function test_logout_does_not_touch_another_accounts_tokens(): void
    {
        [, $mine] = $this->customerWithToken();
        [$other, $theirs] = $this->customerWithToken();

        $this->bearer($mine)->postJson('/api/v1/customer/auth/logout')->assertNoContent();

        $this->assertSame(1, $other->tokens()->count());
        $this->bearer($theirs)->getJson('/api/v1/customer/me')->assertOk();
    }

    public function test_an_expired_token_is_refused(): void
    {
        $account = $this->makeCustomerAccount(['email' => 'rina@example.test']);
        $token = $this->postJson('/api/v1/customer/auth/login', ['email' => 'rina@example.test', 'password' => self::PASSWORD])->json('token');

        $this->bearer($token)->getJson('/api/v1/customer/me')->assertOk();

        // Tepat sebelum kedaluwarsa masih berlaku; sesudahnya tidak.
        $this->travel((int) config('customer_commerce.token_ttl_minutes') - 1)->minutes();
        $this->bearer($token)->getJson('/api/v1/customer/me')->assertOk();

        $this->travel(2)->minutes();
        $this->bearer($token)->getJson('/api/v1/customer/me')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'unauthenticated');

        $this->assertSame(1, $account->tokens()->count(), 'Token kedaluwarsa ditolak oleh guard, bukan karena sudah dihapus.');
    }

    public function test_login_removes_the_accounts_expired_tokens(): void
    {
        $account = $this->makeCustomerAccount(['email' => 'rina@example.test']);
        $account->createToken('customer-app', ['customer'], now()->subMinute());
        $other = $this->makeCustomerAccount();
        $other->createToken('customer-app', ['customer'], now()->subMinute());

        $this->postJson('/api/v1/customer/auth/login', ['email' => 'rina@example.test', 'password' => self::PASSWORD])->assertOk();

        $this->assertSame(1, $account->tokens()->count(), 'Hanya token baru yang tersisa.');
        $this->assertSame(1, $other->tokens()->count(), 'Token akun lain tidak disentuh.');
    }

    public function test_a_token_without_the_customer_ability_is_refused(): void
    {
        $account = $this->makeCustomerAccount();

        foreach ([['lainnya'], []] as $abilities) {
            $token = $account->createToken('customer-app', $abilities, now()->addHour())->plainTextToken;

            $this->bearer($token)->getJson('/api/v1/customer/me')
                ->assertStatus(401)
                ->assertJsonPath('code', 'unauthenticated');
            $this->bearer($token)->postJson('/api/v1/customer/auth/logout')->assertStatus(401);
        }
    }

    public function test_a_disabled_account_loses_access_immediately_and_its_tokens_are_deleted(): void
    {
        [$account, $token] = $this->customerWithToken(['email' => 'rina@example.test']);
        $second = $this->issueCustomerToken($account);
        [$other, $otherToken] = $this->customerWithToken();

        $this->bearer($token)->getJson('/api/v1/customer/me')->assertOk();

        app(CustomerAccountService::class)->disable($account);

        $this->assertNotNull($account->fresh()->disabled_at);
        $this->assertSame(0, $account->tokens()->count());
        $this->assertSame(1, $other->tokens()->count());

        foreach ([$token, $second] as $revoked) {
            $this->bearer($revoked)->getJson('/api/v1/customer/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        }
        $this->bearer($otherToken)->getJson('/api/v1/customer/me')->assertOk();

        // Tidak bisa masuk lagi, dengan galat yang sama seperti kredensial salah.
        $this->withoutBearer()
            ->postJson('/api/v1/customer/auth/login', ['email' => 'rina@example.test', 'password' => self::PASSWORD])
            ->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'customer_account_disabled', 'entity_type' => 'CustomerAccount', 'entity_id' => $account->id,
        ]);
    }

    public function test_a_token_of_a_disabled_account_is_refused_even_if_it_still_exists(): void
    {
        // Lapis kedua: `disabled_at` diisi lewat jalur lain (mis. perbaikan manual)
        // tanpa mencabut token. Middleware tetap harus menolak.
        [$account, $token] = $this->customerWithToken();
        DB::table('customer_accounts')->where('id', $account->id)->update(['disabled_at' => now()]);

        $this->bearer($token)->getJson('/api/v1/customer/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->bearer($token)->putJson('/api/v1/customer/me', ['name' => 'Tidak Boleh'])->assertStatus(401);
    }

    public function test_an_account_whose_contact_no_longer_resolves_is_refused(): void
    {
        [$account, $token] = $this->customerWithToken(['email' => 'rina@example.test']);
        DB::table('customers')->where('id', $account->customer_id)->update(['deleted_at' => now()]);

        $this->bearer($token)->getJson('/api/v1/customer/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->withoutBearer()
            ->postJson('/api/v1/customer/auth/login', ['email' => 'rina@example.test', 'password' => self::PASSWORD])
            ->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_protected_routes_need_a_token(): void
    {
        foreach ([['GET', '/customer/me'], ['PUT', '/customer/me'], ['POST', '/customer/auth/logout']] as [$method, $uri]) {
            $this->json($method, "/api/v1{$uri}")
                ->assertStatus(401)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('code', 'unauthenticated');
        }

        $this->bearer('1|token-karangan-yang-tidak-ada')->getJson('/api/v1/customer/me')->assertStatus(401);
    }
}
