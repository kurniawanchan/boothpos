<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §5) — GET|PUT /customer/me.
 *
 * Profil adalah proyeksi sempit (CustomerAccountResource), bukan
 * CustomerResource staf: catatan internal, akun sosial, alamat kontak dan
 * kolom keamanan akun tidak pernah ikut. E-mail tidak bisa diubah di v1.
 */
class CustomerProfileTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    public function test_me_returns_only_the_public_account_projection(): void
    {
        [$account, $token] = $this->customerWithToken(
            ['email' => 'rina@example.test'],
            ['name' => 'Rina Pelanggan', 'phone' => '081200000001', 'notes' => 'catatan internal staf', 'social_handle' => '@rina', 'address' => 'Jl. Rahasia 1'],
        );

        $response = $this->bearer($token)->getJson('/api/v1/customer/me');

        $response->assertOk()->assertExactJson(['data' => [
            'id' => $account->id,
            'name' => 'Rina Pelanggan',
            'email' => 'rina@example.test',
            'phone' => '081200000001',
            'email_verified' => true,
            'created_at' => $account->created_at->toIso8601String(),
        ]]);

        foreach (['catatan internal staf', '@rina', 'Jl. Rahasia 1', 'password', 'customer_id', 'data_mode', 'disabled_at'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }
        $this->assertTrue($response->headers->has('X-Request-Id'));
    }

    public function test_put_me_updates_name_and_phone_on_the_linked_contact(): void
    {
        [$account, $token] = $this->customerWithToken(['email' => 'rina@example.test'], ['name' => 'Rina Lama', 'phone' => '081200000001']);

        $this->bearer($token)->putJson('/api/v1/customer/me', ['name' => 'Rina Baru', 'phone' => '081299999999'])
            ->assertOk()
            ->assertJsonPath('data.id', $account->id)
            ->assertJsonPath('data.name', 'Rina Baru')
            ->assertJsonPath('data.phone', '081299999999')
            ->assertJsonPath('data.email', 'rina@example.test');

        $this->assertDatabaseHas('customers', ['id' => $account->customer_id, 'name' => 'Rina Baru', 'phone' => '081299999999']);
        $this->bearer($token)->getJson('/api/v1/customer/me')->assertJsonPath('data.name', 'Rina Baru');
    }

    public function test_put_me_cannot_change_the_email_or_any_other_account_field(): void
    {
        [$account, $token] = $this->customerWithToken(['email' => 'rina@example.test'], ['name' => 'Rina', 'email' => 'rina@example.test']);
        $before = DB::table('customer_accounts')->where('id', $account->id)->first();

        $this->bearer($token)->putJson('/api/v1/customer/me', [
            'name' => 'Rina',
            'phone' => null,
            'email' => 'pengganti@example.test',
            'password' => 'kata-sandi-pengganti-1',
            'email_verified_at' => null,
            'disabled_at' => now()->toIso8601String(),
            'customer_id' => 999,
            'artist_id' => 1,
            'notes' => 'disisipkan pelanggan',
        ])->assertOk()->assertJsonPath('data.email', 'rina@example.test')->assertJsonPath('data.email_verified', true);

        $after = DB::table('customer_accounts')->where('id', $account->id)->first();
        $this->assertSame($before->email, $after->email);
        $this->assertSame($before->password, $after->password);
        $this->assertSame($before->customer_id, $after->customer_id);
        $this->assertSame($before->email_verified_at, $after->email_verified_at);
        $this->assertNull($after->disabled_at);

        $contact = Customer::findOrFail($account->customer_id);
        $this->assertSame('rina@example.test', $contact->email);
        $this->assertNull($contact->artist_id);
        $this->assertNotSame('disisipkan pelanggan', $contact->notes);
    }

    public function test_phone_can_be_cleared_and_is_left_alone_when_omitted(): void
    {
        [$account, $token] = $this->customerWithToken([], ['name' => 'Rina', 'phone' => '081200000001']);

        $this->bearer($token)->putJson('/api/v1/customer/me', ['name' => 'Rina Dua'])
            ->assertOk()->assertJsonPath('data.phone', '081200000001');

        $this->bearer($token)->putJson('/api/v1/customer/me', ['name' => 'Rina Dua', 'phone' => null])
            ->assertOk()->assertJsonPath('data.phone', null);

        $this->assertDatabaseHas('customers', ['id' => $account->customer_id, 'name' => 'Rina Dua', 'phone' => null]);
    }

    public function test_put_me_validates_its_input(): void
    {
        [$account, $token] = $this->customerWithToken([], ['name' => 'Rina']);

        $this->bearer($token)->putJson('/api/v1/customer/me', [])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['name']);

        $this->bearer($token)->putJson('/api/v1/customer/me', ['name' => str_repeat('n', 101), 'phone' => str_repeat('1', 31)])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'phone']);

        $this->assertDatabaseHas('customers', ['id' => $account->customer_id, 'name' => 'Rina']);
    }

    public function test_an_account_only_ever_edits_its_own_contact(): void
    {
        [$mine, $token] = $this->customerWithToken([], ['name' => 'Saya']);
        $other = $this->makeCustomerAccount([], ['name' => 'Orang Lain']);

        $this->bearer($token)->putJson('/api/v1/customer/me', ['name' => 'Saya Baru', 'id' => $other->id, 'customer_id' => $other->customer_id])->assertOk();

        $this->assertDatabaseHas('customers', ['id' => $other->customer_id, 'name' => 'Orang Lain']);
        $this->assertDatabaseHas('customers', ['id' => $mine->customer_id, 'name' => 'Saya Baru']);
    }
}
