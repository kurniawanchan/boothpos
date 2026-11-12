<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\Concerns\DataModeScope;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\Setting;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customer commerce — akun pelanggan adalah data bisnis milik SATU mode
 * (DEMO atau LIVE). Akun DEMO tidak bisa masuk ataupun memakai token lamanya
 * setelah toko pindah ke LIVE, dan sebaliknya; kalau tidak, akun uji coba
 * bisa membuat pesanan sungguhan (atau data pelanggan sungguhan tampil di DEMO).
 */
class CustomerCommerceDataModeAuthTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    private const PASSWORD = 'password';

    private function switchStoreTo(string $mode): void
    {
        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => $mode]);
    }

    private function login(string $email)
    {
        return $this->withoutBearer()->postJson('/api/v1/customer/auth/login', ['email' => $email, 'password' => self::PASSWORD]);
    }

    public function test_a_demo_account_cannot_authenticate_once_the_store_is_live(): void
    {
        $this->switchStoreTo(ModeGate::DEMO);
        [$account, $token] = $this->customerWithToken(['email' => 'demo@example.test']);
        $this->assertSame('demo', $account->data_mode);

        $this->bearer($token)->getJson('/api/v1/customer/me')->assertOk();
        $this->login('demo@example.test')->assertOk();

        $this->switchStoreTo(ModeGate::LIVE);

        $this->bearer($token)->getJson('/api/v1/customer/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->bearer($token)->putJson('/api/v1/customer/me', ['name' => 'Tidak Boleh'])->assertStatus(401);
        $this->login('demo@example.test')->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');

        // Kembali ke DEMO: akun dan token lamanya berlaku lagi (tidak ada yang dihapus).
        $this->switchStoreTo(ModeGate::DEMO);
        $this->bearer($token)->getJson('/api/v1/customer/me')->assertOk()->assertJsonPath('data.id', $account->id);
    }

    public function test_a_live_account_cannot_authenticate_while_the_store_is_in_demo(): void
    {
        [$account, $token] = $this->customerWithToken(['email' => 'live@example.test']);
        $this->assertSame('live', $account->data_mode);
        $this->bearer($token)->getJson('/api/v1/customer/me')->assertOk();

        $this->switchStoreTo(ModeGate::DEMO);

        $this->bearer($token)->getJson('/api/v1/customer/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->bearer($token)->postJson('/api/v1/customer/auth/logout')->assertStatus(401);
        $this->login('live@example.test')->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
        $this->assertSame(1, $account->tokens()->count(), 'Logout yang ditolak tidak mencabut token mode lain.');

        $this->switchStoreTo(ModeGate::LIVE);
        $this->bearer($token)->getJson('/api/v1/customer/me')->assertOk();
    }

    public function test_the_same_email_registers_independently_in_each_mode(): void
    {
        $this->enableCustomerCommerce();
        $payload = ['name' => 'Rina Pelanggan', 'email' => 'rina@example.test', 'password' => 'kata-sandi-uji-123'];

        $this->postJson('/api/v1/customer/auth/register', $payload)->assertCreated();
        $this->postJson('/api/v1/customer/auth/register', $payload)->assertStatus(422)->assertJsonPath('code', 'registration_failed');

        $this->switchStoreTo(ModeGate::DEMO);
        $demoToken = $this->postJson('/api/v1/customer/auth/register', $payload)->assertCreated()->json('token');

        $accounts = CustomerAccount::withoutGlobalScope(DataModeScope::class)->where('email', 'rina@example.test')->get();
        $this->assertEqualsCanonicalizing(['live', 'demo'], $accounts->pluck('data_mode')->all());

        // Tiap akun dan kontaknya berada di mode yang sama.
        foreach ($accounts as $account) {
            $contact = Customer::withoutGlobalScopes()->findOrFail($account->customer_id);
            $this->assertSame($account->data_mode, $contact->data_mode);
        }

        $this->bearer($demoToken)->getJson('/api/v1/customer/me')->assertOk();
        $this->switchStoreTo(ModeGate::LIVE);
        $this->bearer($demoToken)->getJson('/api/v1/customer/me')->assertStatus(401);
    }
}
