<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\CustomerAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §7) — alamat tersimpan milik akun pelanggan.
 *
 * Yang dijaga: (1) alamat hanya dijangkau lewat pemiliknya (id milik akun
 * lain menjawab 404 yang SAMA dengan id yang tidak ada); (2) selama akun
 * punya alamat, tepat SATU yang utama; (3) batas jumlah alamat; (4) saklar
 * fitur menghentikan tulis, bukan baca.
 */
class CustomerAddressTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    private const URL = '/api/v1/customer/addresses';

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCustomerCommerce();
    }

    /** @return array<string,mixed> */
    private function body(array $overrides = []): array
    {
        return $overrides + [
            'label' => 'Rumah',
            'recipient_name' => 'Rina Penerima',
            'phone' => '081200000001',
            'address_line' => 'Jl. Melati No. 5',
            'city' => 'Bandung',
            'province' => 'Jawa Barat',
            'postal_code' => '40111',
            'notes' => 'Pagar hijau',
        ];
    }

    private function defaultIds(int $accountId): array
    {
        return DB::table('customer_addresses')->where('customer_account_id', $accountId)->where('is_default', true)->pluck('id')->all();
    }

    public function test_create_returns_the_contract_shape_and_forces_the_first_address_to_be_default(): void
    {
        [$account, $token] = $this->customerWithToken();

        $response = $this->bearer($token)->postJson(self::URL, $this->body(['is_default' => false]));

        $id = CustomerAddress::where('customer_account_id', $account->id)->value('id');
        $response->assertCreated()->assertExactJson(['data' => [
            'id' => $id,
            'label' => 'Rumah',
            'recipient_name' => 'Rina Penerima',
            'phone' => '081200000001',
            'address_line' => 'Jl. Melati No. 5',
            'city' => 'Bandung',
            'province' => 'Jawa Barat',
            'postal_code' => '40111',
            'notes' => 'Pagar hijau',
            'is_default' => true,
        ]]);
        $this->assertTrue($response->headers->has('X-Request-Id'));
        $this->assertSame([$id], $this->defaultIds($account->id));
    }

    public function test_optional_fields_may_be_omitted_or_null(): void
    {
        [, $token] = $this->customerWithToken();

        $this->bearer($token)->postJson(self::URL, [
            'recipient_name' => 'Rina', 'phone' => '0812', 'address_line' => 'Jl. A', 'city' => 'Bandung', 'province' => 'Jawa Barat',
            'postal_code' => null,
        ])->assertCreated()
            ->assertJsonPath('data.label', null)
            ->assertJsonPath('data.postal_code', null)
            ->assertJsonPath('data.notes', null);
    }

    public function test_a_new_default_unsets_the_previous_default(): void
    {
        [$account, $token] = $this->customerWithToken();

        $first = $this->bearer($token)->postJson(self::URL, $this->body(['label' => 'Rumah']))->json('data.id');
        $second = $this->bearer($token)->postJson(self::URL, $this->body(['label' => 'Kantor']))->assertCreated()->assertJsonPath('data.is_default', false)->json('data.id');
        $this->assertSame([$first], $this->defaultIds($account->id));

        $third = $this->bearer($token)->postJson(self::URL, $this->body(['label' => 'Kos', 'is_default' => true]))->assertCreated()->assertJsonPath('data.is_default', true)->json('data.id');
        $this->assertSame([$third], $this->defaultIds($account->id));

        // Daftar: alamat utama lebih dulu, lalu yang terbaru.
        $this->bearer($token)->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.0.id', $third)
            ->assertJsonPath('data.1.id', $second)
            ->assertJsonPath('data.2.id', $first)
            ->assertJsonCount(3, 'data');
    }

    public function test_update_changes_fields_and_can_move_the_default(): void
    {
        [$account, $token] = $this->customerWithToken();
        $first = $this->makeCustomerAddress($account, ['is_default' => true, 'label' => 'Rumah', 'notes' => 'lama', 'postal_code' => '40111']);
        $second = $this->makeCustomerAddress($account, ['label' => 'Kantor']);

        $this->bearer($token)->putJson(self::URL.'/'.$second->id, $this->body(['label' => 'Kantor Baru', 'city' => 'Cimahi', 'notes' => null, 'is_default' => true]))
            ->assertOk()
            ->assertJsonPath('data.id', $second->id)
            ->assertJsonPath('data.label', 'Kantor Baru')
            ->assertJsonPath('data.city', 'Cimahi')
            ->assertJsonPath('data.notes', null)
            ->assertJsonPath('data.is_default', true);

        $this->assertSame([$second->id], $this->defaultIds($account->id));
        $this->assertDatabaseHas('customer_addresses', ['id' => $first->id, 'is_default' => false, 'notes' => 'lama']);
    }

    public function test_update_cannot_leave_the_account_without_a_default(): void
    {
        [$account, $token] = $this->customerWithToken();
        $only = $this->makeCustomerAddress($account, ['is_default' => true]);

        // `is_default: false` pada alamat utama diabaikan: memindahkan utama = menandai alamat LAIN.
        $this->bearer($token)->putJson(self::URL.'/'.$only->id, $this->body(['is_default' => false]))
            ->assertOk()->assertJsonPath('data.is_default', true);

        $this->assertSame([$only->id], $this->defaultIds($account->id));
    }

    public function test_show_returns_one_own_address(): void
    {
        [$account, $token] = $this->customerWithToken();
        $address = $this->makeCustomerAddress($account, ['is_default' => true, 'label' => 'Rumah']);

        $response = $this->bearer($token)->getJson(self::URL.'/'.$address->id);

        $response->assertOk()->assertJsonPath('data.id', $address->id)->assertJsonPath('data.label', 'Rumah');
        $this->assertSame(
            ['id', 'label', 'recipient_name', 'phone', 'address_line', 'city', 'province', 'postal_code', 'notes', 'is_default'],
            array_keys($response->json('data')),
        );
    }

    public function test_deleting_the_default_promotes_the_most_recently_created_remaining_address(): void
    {
        [$account, $token] = $this->customerWithToken();
        $oldest = $this->makeCustomerAddress($account);
        $default = $this->makeCustomerAddress($account, ['is_default' => true]);
        $newest = $this->makeCustomerAddress($account);

        $this->bearer($token)->deleteJson(self::URL.'/'.$default->id)->assertNoContent();

        $this->assertDatabaseMissing('customer_addresses', ['id' => $default->id]);
        $this->assertSame([$newest->id], $this->defaultIds($account->id));

        // Menghapus yang bukan utama tidak menggeser apa pun.
        $this->bearer($token)->deleteJson(self::URL.'/'.$oldest->id)->assertNoContent();
        $this->assertSame([$newest->id], $this->defaultIds($account->id));

        // Menghapus alamat terakhir: akun tanpa alamat, tanpa galat.
        $this->bearer($token)->deleteJson(self::URL.'/'.$newest->id)->assertNoContent();
        $this->bearer($token)->getJson(self::URL)->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_validation_errors_use_the_problem_envelope(): void
    {
        [, $token] = $this->customerWithToken();

        $this->bearer($token)->postJson(self::URL, [
            'label' => str_repeat('a', 51),
            'recipient_name' => '',
            'phone' => str_repeat('1', 31),
            'city' => 'Bandung',
            'postal_code' => '12345678901',
            'is_default' => 'mungkin',
        ])->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['label', 'recipient_name', 'phone', 'address_line', 'province', 'postal_code', 'is_default']);

        $this->assertSame(0, CustomerAddress::count());
    }

    public function test_the_address_limit_answers_409_and_creates_nothing(): void
    {
        config(['customer_commerce.max_addresses' => 2]);
        [$account, $token] = $this->customerWithToken();

        $this->bearer($token)->postJson(self::URL, $this->body())->assertCreated();
        $this->bearer($token)->postJson(self::URL, $this->body())->assertCreated();

        $this->bearer($token)->postJson(self::URL, $this->body(['is_default' => true]))
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'address_limit_reached')
            ->assertJsonMissingPath('errors');

        $this->assertSame(2, CustomerAddress::where('customer_account_id', $account->id)->count());
        // Alamat utama lama tidak ikut dicabut oleh permintaan yang ditolak.
        $this->assertCount(1, $this->defaultIds($account->id));

        // Setelah satu dihapus, alamat baru bisa dibuat lagi.
        $this->bearer($token)->deleteJson(self::URL.'/'.CustomerAddress::where('customer_account_id', $account->id)->max('id'))->assertNoContent();
        $this->bearer($token)->postJson(self::URL, $this->body())->assertCreated();
    }

    public function test_another_accounts_address_is_a_404_identical_to_an_unknown_id(): void
    {
        [$owner] = $this->customerWithToken();
        [$intruder, $intruderToken] = $this->customerWithToken();
        $address = $this->makeCustomerAddress($owner, ['is_default' => true, 'recipient_name' => 'Pemilik Asli', 'address_line' => 'Jl. Rahasia 9']);
        $own = $this->makeCustomerAddress($intruder, ['is_default' => true]);
        $unknownId = $address->id + 9999;

        $strip = function ($response): array {
            $response->assertNotFound()->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('code', 'not_found');
            $this->assertStringNotContainsString('Pemilik Asli', $response->getContent());
            $this->assertStringNotContainsString('Jl. Rahasia 9', $response->getContent());
            $this->assertStringNotContainsString('CustomerAddress', $response->getContent());

            return collect($response->json())->except('request_id')->all();
        };

        foreach ([
            fn (int $id) => $this->bearer($intruderToken)->getJson(self::URL.'/'.$id),
            fn (int $id) => $this->bearer($intruderToken)->putJson(self::URL.'/'.$id, $this->body(['is_default' => true])),
            fn (int $id) => $this->bearer($intruderToken)->deleteJson(self::URL.'/'.$id),
        ] as $attempt) {
            $this->assertSame($strip($attempt($unknownId)), $strip($attempt($address->id)));
        }

        // Tidak ada yang berubah di kedua akun.
        $this->assertDatabaseHas('customer_addresses', ['id' => $address->id, 'recipient_name' => 'Pemilik Asli', 'is_default' => true]);
        $this->assertSame([$own->id], $this->defaultIds($intruder->id));

        // Daftar si penyusup hanya berisi miliknya.
        $this->bearer($intruderToken)->getJson(self::URL)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
    }

    public function test_a_client_cannot_assign_the_address_to_another_account(): void
    {
        [$owner, $token] = $this->customerWithToken();
        [$other] = $this->customerWithToken();

        $this->bearer($token)->postJson(self::URL, $this->body(['customer_account_id' => $other->id, 'id' => 999999]))->assertCreated();

        $this->assertSame(1, CustomerAddress::where('customer_account_id', $owner->id)->count());
        $this->assertSame(0, CustomerAddress::where('customer_account_id', $other->id)->count());
        $this->assertDatabaseMissing('customer_addresses', ['id' => 999999]);
    }

    public function test_a_non_numeric_id_is_a_404(): void
    {
        [, $token] = $this->customerWithToken();

        $this->bearer($token)->getJson(self::URL.'/abc')->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->bearer($token)->deleteJson(self::URL.'/1%20OR%201=1')->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    public function test_addresses_require_a_customer_token(): void
    {
        $this->getJson(self::URL)->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->postJson(self::URL, $this->body())->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->assertSame(0, CustomerAddress::count());
    }

    public function test_writes_are_blocked_but_reads_still_work_while_the_feature_is_off(): void
    {
        [$account, $token] = $this->customerWithToken();
        $address = $this->makeCustomerAddress($account, ['is_default' => true, 'label' => 'Rumah']);
        $this->disableCustomerCommerce();

        foreach ([
            $this->bearer($token)->postJson(self::URL, $this->body()),
            $this->bearer($token)->putJson(self::URL.'/'.$address->id, $this->body(['label' => 'Diubah'])),
            $this->bearer($token)->deleteJson(self::URL.'/'.$address->id),
        ] as $response) {
            $response->assertStatus(503)->assertJsonPath('code', 'customer_commerce_disabled')->assertHeader('Retry-After', '3600');
        }

        $this->assertDatabaseHas('customer_addresses', ['id' => $address->id, 'label' => 'Rumah']);
        $this->assertSame(1, CustomerAddress::count());

        $this->bearer($token)->getJson(self::URL)->assertOk()->assertJsonCount(1, 'data');
        $this->bearer($token)->getJson(self::URL.'/'.$address->id)->assertOk()->assertJsonPath('data.id', $address->id);
    }
}
