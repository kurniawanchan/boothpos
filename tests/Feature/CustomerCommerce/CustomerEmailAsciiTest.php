<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\Customer;
use App\Models\CustomerAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §5) — e-mail pelanggan hanya boleh ASCII yang
 * tercetak.
 *
 * Kolom memakai kolasi `utf8mb4_unicode_ci`, jadi di `WHERE email = ?`
 * `víctim@x.com` SAMA dengan `victim@x.com` (juga karakter kontrol yang
 * "diabaikan" kolasi). Pembatas laju per e-mail menghitung kuncinya dari
 * sha1(huruf kecil(trim(e-mail))) tanpa tahu itu, sehingga tiap ejaan aksen
 * mendapat ember baru dan satu akun bisa ditebak tanpa batas. Obatnya di
 * sumber: e-mail non-ASCII ditolak saat daftar dan TIDAK PERNAH sampai ke
 * pencarian basis data saat masuk.
 */
class CustomerEmailAsciiTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    private const PASSWORD = 'password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCustomerCommerce();
    }

    /** @return array<string, array{0: string}> */
    public static function nonAsciiEmails(): array
    {
        return [
            'huruf beraksen di bagian lokal' => ["v\u{00ED}ctim@example.test"],
            'domain IDN' => ["user@m\u{00FC}ller.example"],
            'huruf lebar penuh' => ["\u{FF56}ictim@example.test"],
            'karakter kontrol yang diabaikan kolasi' => ["vic\u{0001}tim@example.test"],
            'spasi tak terlihat' => ["victim\u{200B}@example.test"],
        ];
    }

    private function strip($response): array
    {
        $body = $response->json();
        unset($body['request_id']);

        return $body;
    }

    #[DataProvider('nonAsciiEmails')]
    public function test_register_refuses_a_non_ascii_email(string $email): void
    {
        $response = $this->postJson('/api/v1/customer/auth/register', [
            'name' => 'Penyerang', 'email' => $email, 'password' => 'kata-sandi-uji-123',
        ]);

        $response->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'validation_failed');
        $this->assertArrayHasKey('email', $response->json('errors'));
        $this->assertSame(0, CustomerAccount::count());
        $this->assertSame(0, Customer::count());
    }

    public function test_the_ascii_email_message_is_a_sentence_in_both_languages(): void
    {
        $body = ['name' => 'Penyerang', 'email' => "v\u{00ED}ctim@example.test", 'password' => 'kata-sandi-uji-123'];

        $id = $this->withHeader('Accept-Language', 'id')->postJson('/api/v1/customer/auth/register', $body);
        $en = $this->withHeader('Accept-Language', 'en')->postJson('/api/v1/customer/auth/register', $body);

        $this->assertContains('E-mail hanya boleh memuat huruf, angka, dan simbol standar (tanpa huruf beraksen).', $id->json('errors.email'));
        $this->assertContains('The e-mail may only contain standard letters, digits and symbols (no accents).', $en->json('errors.email'));
    }

    public function test_an_ascii_email_in_any_case_and_with_a_punycode_domain_still_registers(): void
    {
        $this->postJson('/api/v1/customer/auth/register', [
            'name' => 'Rina', 'email' => ' Rina.Pelanggan+toko@XN--MLLER-KVA.example ', 'password' => 'kata-sandi-uji-123',
        ])->assertCreated()->assertJsonPath('account.email', 'rina.pelanggan+toko@xn--mller-kva.example');
    }

    #[DataProvider('nonAsciiEmails')]
    public function test_login_with_a_variant_of_an_existing_email_cannot_authenticate_even_with_the_right_password(string $variant): void
    {
        $account = $this->makeCustomerAccount(['email' => 'victim@example.test']);

        $unknown = $this->postJson('/api/v1/customer/auth/login', ['email' => 'tidak-ada@example.test', 'password' => self::PASSWORD]);
        $response = $this->postJson('/api/v1/customer/auth/login', ['email' => $variant, 'password' => self::PASSWORD]);

        $response->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'invalid_credentials')
            ->assertJsonMissingPath('errors');
        // Badan SAMA dengan e-mail yang tidak dikenal: tidak ada petunjuk bahwa akun aslinya ada.
        $this->assertSame($this->strip($unknown), $this->strip($response));

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertNull($account->fresh()->last_login_at);
    }

    public function test_the_accent_variant_really_does_match_the_stored_email_in_the_database(): void
    {
        // Syarat bermakna test di atas: tanpa penjaga, kolasi MEMANG menyamakan keduanya. Kalau ini gagal,
        // basis data uji tidak berperilaku seperti produksi dan test "tidak bisa masuk" menjadi hampa.
        $this->makeCustomerAccount(['email' => 'victim@example.test']);

        foreach (["v\u{00ED}ctim@example.test", "vic\u{0001}tim@example.test"] as $variant) {
            $this->assertTrue(
                DB::table('customer_accounts')->where('email', $variant)->exists(),
                'Kolasi basis data tidak menyamakan varian ini; test penjaga ASCII tidak bermakna.',
            );
        }
    }

    public function test_the_non_ascii_variant_never_reaches_the_database_lookup(): void
    {
        $this->makeCustomerAccount(['email' => 'victim@example.test']);

        $lookups = [];
        DB::listen(function ($query) use (&$lookups) {
            if (str_contains($query->sql, 'customer_accounts') && str_contains($query->sql, 'select')) {
                $lookups[] = $query->bindings;
            }
        });

        $this->postJson('/api/v1/customer/auth/login', ['email' => "v\u{00ED}ctim@example.test", 'password' => self::PASSWORD])->assertStatus(401);

        foreach ($lookups as $bindings) {
            foreach ($bindings as $binding) {
                $this->assertNotSame("v\u{00ED}ctim@example.test", $binding, 'E-mail non-ASCII sampai ke query.');
            }
        }
    }

    public function test_case_and_whitespace_variants_still_log_in(): void
    {
        $this->makeCustomerAccount(['email' => 'victim@example.test']);

        foreach (['VICTIM@example.test', '  Victim@Example.TEST  ', "\tvictim@example.test\n"] as $variant) {
            $this->postJson('/api/v1/customer/auth/login', ['email' => $variant, 'password' => self::PASSWORD])->assertOk();
        }
    }

    public function test_case_and_whitespace_variants_share_one_limiter_bucket(): void
    {
        $limit = (int) config('customer_commerce.rate_limits.login.per_email');
        $this->makeCustomerAccount(['email' => 'victim@example.test']);
        $variants = ['victim@example.test', 'VICTIM@example.test', ' Victim@example.test ', "victim@example.test\n"];

        for ($i = 0; $i < $limit; $i++) {
            $this->postJson('/api/v1/customer/auth/login', ['email' => $variants[$i % count($variants)], 'password' => 'salah-sekali-123'])
                ->assertStatus(401);
        }

        foreach ($variants as $variant) {
            $this->postJson('/api/v1/customer/auth/login', ['email' => $variant, 'password' => self::PASSWORD])
                ->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        }
    }
}
