<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §5) — POST /customer/auth/register.
 *
 * Yang dijaga: akun SELALU membuat kontak `customers` baru (tidak pernah
 * menempel ke kontak buatan staf), kata sandi hanya tersimpan sebagai hash,
 * dan e-mail yang sudah terdaftar ditolak dengan galat generik tanpa
 * `errors.email` supaya respons tidak bisa dipakai menebak e-mail terdaftar.
 */
class CustomerRegisterTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    private const PASSWORD = 'kata-sandi-uji-123';

    private const PROBLEM_KEYS = ['type', 'title', 'status', 'detail', 'code', 'request_id'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCustomerCommerce();
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Rina Pelanggan',
            'email' => 'rina@example.test',
            'password' => self::PASSWORD,
            'phone' => '081200000001',
        ];
    }

    public function test_register_creates_a_contact_and_an_account_and_returns_a_token(): void
    {
        Carbon::setTestNow('2026-11-11 10:00:00');

        $response = $this->postJson('/api/v1/customer/auth/register', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('account.name', 'Rina Pelanggan')
            ->assertJsonPath('account.email', 'rina@example.test')
            ->assertJsonPath('account.phone', '081200000001')
            ->assertJsonPath('account.email_verified', false);

        $this->assertSame(['token', 'token_type', 'expires_at', 'account'], array_keys($response->json()));
        $this->assertSame(['id', 'name', 'email', 'phone', 'email_verified', 'created_at'], array_keys($response->json('account')));
        $this->assertTrue(
            Carbon::parse($response->json('expires_at'))->equalTo(now()->addMinutes((int) config('customer_commerce.token_ttl_minutes'))),
            'expires_at harus sekarang + token_ttl_minutes.',
        );

        $account = CustomerAccount::sole();
        $customer = Customer::sole();

        $this->assertSame($account->id, $response->json('account.id'));
        $this->assertSame($customer->id, $account->customer_id);
        $this->assertSame('Rina Pelanggan', $customer->name);
        $this->assertSame('081200000001', $customer->phone);
        $this->assertSame('rina@example.test', $customer->email);
        $this->assertNull($customer->artist_id, 'Kontak hasil registrasi tidak pernah milik seller.');
        $this->assertSame(ModeGate::current(), $account->data_mode);
        $this->assertSame($account->data_mode, $customer->data_mode);
        $this->assertNull($account->email_verified_at);
        $this->assertNull($account->disabled_at);

        // Token: nama, ability dan kedaluwarsa persis seperti kontrak.
        $token = $account->tokens()->sole();
        $this->assertSame('customer-app', $token->name);
        $this->assertSame(['customer'], $token->abilities);
        $this->assertNotNull($token->expires_at);

        $this->bearer($response->json('token'))
            ->getJson('/api/v1/customer/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'rina@example.test');
    }

    public function test_the_password_is_stored_only_as_a_hash_and_never_echoed(): void
    {
        $response = $this->postJson('/api/v1/customer/auth/register', $this->payload())->assertCreated();

        $stored = DB::table('customer_accounts')->value('password');

        $this->assertNotSame(self::PASSWORD, $stored);
        $this->assertStringNotContainsString(self::PASSWORD, $stored);
        $this->assertTrue(Hash::check(self::PASSWORD, $stored));
        $this->assertStringNotContainsString(self::PASSWORD, $response->getContent());
        $response->assertJsonMissingPath('account.password');

        // Tidak ada tabel lain yang menyimpan kata sandi polos (kontak, log aktivitas).
        $this->assertStringNotContainsString(self::PASSWORD, json_encode(DB::table('customers')->get()));
        $this->assertStringNotContainsString(self::PASSWORD, json_encode(DB::table('activity_logs')->get()));
    }

    public function test_registration_is_audited_without_personal_data(): void
    {
        $this->postJson('/api/v1/customer/auth/register', $this->payload())->assertCreated();

        $log = DB::table('activity_logs')->where('action', 'customer_account_registered')->sole();

        $this->assertNull($log->user_id, 'Akun pelanggan bukan pengguna staf: user_id selalu null.');
        $this->assertSame('CustomerAccount', $log->entity_type);
        $this->assertSame(CustomerAccount::sole()->id, (int) $log->entity_id);
        $this->assertStringNotContainsString('rina@example.test', json_encode($log));
        $this->assertStringNotContainsString('Rina', json_encode($log));
        $this->assertStringNotContainsString('0812', json_encode($log));
    }

    public function test_the_email_is_stored_trimmed_and_lower_cased(): void
    {
        $this->postJson('/api/v1/customer/auth/register', $this->payload(['email' => '  Rina@Example.TEST ']))
            ->assertCreated()
            ->assertJsonPath('account.email', 'rina@example.test');

        $this->assertSame('rina@example.test', DB::table('customer_accounts')->value('email'));
    }

    public function test_phone_is_optional(): void
    {
        $payload = $this->payload();
        unset($payload['phone']);

        $this->postJson('/api/v1/customer/auth/register', $payload)
            ->assertCreated()
            ->assertJsonPath('account.phone', null);
    }

    public function test_register_never_links_to_an_existing_staff_contact_with_the_same_email(): void
    {
        $staffContact = Customer::factory()->create(['email' => 'rina@example.test', 'name' => 'Kontak Buatan Staf']);

        $this->postJson('/api/v1/customer/auth/register', $this->payload())->assertCreated();

        $this->assertSame(2, Customer::count());
        $this->assertNull($staffContact->fresh()->account, 'Kontak buatan staf tidak boleh mendadak punya akun.');
        $this->assertNotSame($staffContact->id, CustomerAccount::sole()->customer_id);
        $this->assertSame('Kontak Buatan Staf', $staffContact->fresh()->name);
    }

    public function test_an_already_registered_email_is_refused_generically(): void
    {
        $this->postJson('/api/v1/customer/auth/register', $this->payload())->assertCreated();

        $response = $this->withHeader('Accept-Language', 'id')
            ->postJson('/api/v1/customer/auth/register', $this->payload(['email' => 'RINA@example.test', 'name' => 'Orang Lain']));

        $response->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'registration_failed')
            ->assertJsonPath('status', 422)
            ->assertJsonPath('detail', 'Pendaftaran tidak dapat diproses. Periksa data Anda, atau masuk jika sudah punya akun.')
            ->assertJsonMissingPath('errors');

        // Bentuknya sama dengan galat non-validasi lain: tidak ada petunjuk bahwa e-mail-lah penyebabnya.
        $this->assertSame(self::PROBLEM_KEYS, array_keys($response->json()));
        $this->assertStringNotContainsStringIgnoringCase('rina@example.test', $response->getContent());
        $this->assertStringNotContainsStringIgnoringCase('email', $response->json('detail'));

        // Tidak ada baris yatim: kontak kedua tidak ikut tersimpan.
        $this->assertSame(1, Customer::count());
        $this->assertSame(1, CustomerAccount::count());
    }

    public function test_the_refusal_has_the_same_shape_as_any_other_non_validation_failure(): void
    {
        $this->postJson('/api/v1/customer/auth/register', $this->payload())->assertCreated();

        $duplicate = $this->postJson('/api/v1/customer/auth/register', $this->payload());
        $unauthenticated = $this->withoutBearer()->getJson('/api/v1/customer/me');

        $this->assertSame(array_keys($unauthenticated->json()), array_keys($duplicate->json()));
        $this->assertSame(
            array_map('gettype', $unauthenticated->json()),
            array_map('gettype', $duplicate->json()),
        );
    }

    public function test_a_unique_index_race_ends_as_the_same_generic_refusal_not_a_500(): void
    {
        // Balapan: akun dengan e-mail yang sama masuk TEPAT setelah pemeriksaan
        // "sudah terdaftar?" lolos. Indeks unik (email, data_mode) adalah jaring
        // terakhir; hasilnya harus galat generik yang sama dan tanpa kontak yatim.
        $competitor = Customer::factory()->create(['artist_id' => null]);
        $armed = true;

        CustomerAccount::creating(function () use (&$armed, $competitor) {
            if (! $armed) {
                return;
            }
            $armed = false;

            DB::table('customer_accounts')->insert([
                'customer_id' => $competitor->id,
                'email' => 'rina@example.test',
                'password' => Hash::make('nilai-uji-lain'),
                'data_mode' => ModeGate::current(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $response = $this->postJson('/api/v1/customer/auth/register', $this->payload());

        $response->assertStatus(422)->assertJsonPath('code', 'registration_failed')->assertJsonMissingPath('errors');
        // Satu koneksi uji: baris pesaing disisipkan DI DALAM transaksi registrasi, jadi ikut
        // dibatalkan bersama transaksi itu. Yang dibuktikan: tidak ada akun maupun kontak milik
        // registrasi yang kalah yang tersisa (hanya kontak pesaing, dibuat sebelum transaksi).
        $this->assertSame(0, CustomerAccount::count());
        $this->assertSame([$competitor->id], Customer::pluck('id')->all(), 'Kontak milik registrasi yang kalah harus ikut dibatalkan.');
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_shape_errors_are_reported_per_field(): void
    {
        $response = $this->postJson('/api/v1/customer/auth/register', []);

        $response->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['name', 'email', 'password'])
            ->assertJsonMissingValidationErrors(['phone']);

        $this->postJson('/api/v1/customer/auth/register', $this->payload(['email' => 'bukan-email']))
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['email']);

        $this->postJson('/api/v1/customer/auth/register', $this->payload(['password' => 'pendek1']))
            ->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->postJson('/api/v1/customer/auth/register', $this->payload(['password' => str_repeat('a', 73)]))
            ->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->postJson('/api/v1/customer/auth/register', $this->payload(['name' => str_repeat('n', 101), 'phone' => str_repeat('1', 31)]))
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'phone']);

        $this->assertSame(0, CustomerAccount::count());
        $this->assertSame(0, Customer::count());
    }

    public function test_client_supplied_identity_fields_are_ignored(): void
    {
        $other = $this->makeCustomerAccount();

        $this->postJson('/api/v1/customer/auth/register', $this->payload([
            'customer_id' => $other->customer_id,
            'artist_id' => 1,
            'email_verified_at' => now()->toIso8601String(),
            'disabled_at' => null,
            'data_mode' => 'demo',
        ]))->assertCreated()->assertJsonPath('account.email_verified', false);

        $account = CustomerAccount::where('email', 'rina@example.test')->sole();

        $this->assertNotSame($other->customer_id, $account->customer_id);
        $this->assertNull($account->customer->artist_id);
        $this->assertSame(ModeGate::current(), $account->data_mode);
    }
}
