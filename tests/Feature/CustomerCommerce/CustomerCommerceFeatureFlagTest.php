<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerCommerceGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §2) — saklar fitur `customer_commerce_enabled`
 * dan konfigurasi publik GET /storefront/config.
 *
 * Bawaan MATI (baris settings tidak ada). Saat mati: pendaftaran ditolak 503
 * `customer_commerce_disabled`, tetapi pelanggan lama tetap bisa masuk dan
 * membuka profilnya (supaya pesanan yang sudah dikirim tetap bisa diikuti),
 * dan /storefront/config tetap menjawab `enabled: false` agar aplikasi tahu
 * harus menampilkan "toko sedang tidak menerima pesanan".
 */
class CustomerCommerceFeatureFlagTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    private function putSettings(array $items)
    {
        return $this->putJson('/api/v1/settings', ['settings' => $items]);
    }

    public function test_the_gate_is_off_until_the_setting_row_says_otherwise(): void
    {
        $this->assertFalse(CustomerCommerceGate::enabled());

        $this->enableCustomerCommerce();
        $this->assertTrue(CustomerCommerceGate::enabled());

        $this->disableCustomerCommerce();
        $this->assertFalse(CustomerCommerceGate::enabled());

        // (bool) "false" bernilai true di PHP: nilai teks harus dibaca lewat filter_var.
        Setting::updateOrCreate(['key' => CustomerCommerceGate::KEY], ['value' => 'false']);
        $this->assertFalse(CustomerCommerceGate::enabled());
        Setting::updateOrCreate(['key' => CustomerCommerceGate::KEY], ['value' => 'true']);
        $this->assertTrue(CustomerCommerceGate::enabled());
    }

    public function test_config_answers_even_when_the_feature_is_off(): void
    {
        $response = $this->getJson('/api/v1/storefront/config');

        $response->assertOk()->assertJsonPath('data.enabled', false);
        $this->assertSame(
            ['enabled', 'store_name', 'currency', 'fulfilment_methods', 'pickup_instructions', 'courier_instructions', 'max_qty_per_line', 'max_lines_per_order'],
            array_keys($response->json('data')),
        );
        $this->assertTrue($response->headers->has('X-Request-Id'));
    }

    public function test_config_reports_the_store_and_the_checkout_limits(): void
    {
        $this->enableCustomerCommerce();
        Setting::updateOrCreate(['key' => 'store_name'], ['value' => 'Toko Sakana']);
        Setting::updateOrCreate(['key' => 'store_name_demo'], ['value' => 'Toko Demo']);
        Setting::updateOrCreate(['key' => 'customer_commerce_pickup_instructions'], ['value' => "Ambil di booth A12.\nBawa nomor pesanan."]);

        $this->getJson('/api/v1/storefront/config')->assertOk()->assertExactJson(['data' => [
            'enabled' => true,
            'store_name' => 'Toko Sakana',
            'currency' => 'IDR',
            'fulfilment_methods' => ['pickup', 'courier'],
            'pickup_instructions' => "Ambil di booth A12.\nBawa nomor pesanan.",
            'courier_instructions' => null,
            'max_qty_per_line' => 20,
            'max_lines_per_order' => 50,
        ]]);

        // Nama toko mengikuti mode data aktif, seperti GET /settings/features.
        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'demo']);
        $this->getJson('/api/v1/storefront/config')->assertJsonPath('data.store_name', 'Toko Demo');
    }

    public function test_config_always_has_a_store_name_and_treats_blank_instructions_as_null(): void
    {
        Setting::updateOrCreate(['key' => 'customer_commerce_courier_instructions'], ['value' => '   ']);

        $this->getJson('/api/v1/storefront/config')
            ->assertOk()
            ->assertJsonPath('data.store_name', 'BoothPOS')
            ->assertJsonPath('data.courier_instructions', null);
    }

    public function test_config_is_public_and_ignores_any_token(): void
    {
        [, $token] = $this->customerWithToken();
        $staffToken = $this->issueStaffToken(User::factory()->create(['role' => 'owner']));

        $this->getJson('/api/v1/storefront/config')->assertOk();
        $this->bearer($token)->getJson('/api/v1/storefront/config')->assertOk();
        $this->bearer($staffToken)->getJson('/api/v1/storefront/config')->assertOk();
        $this->bearer('1|token-karangan-yang-tidak-ada')->getJson('/api/v1/storefront/config')->assertOk();
    }

    public function test_register_is_refused_with_503_while_the_feature_is_off(): void
    {
        $response = $this->withHeader('Accept-Language', 'id')->postJson('/api/v1/customer/auth/register', [
            'name' => 'Rina Pelanggan', 'email' => 'rina@example.test', 'password' => 'kata-sandi-uji-123',
        ]);

        $response->assertStatus(503)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('Retry-After', '3600')
            ->assertJsonPath('code', 'customer_commerce_disabled')
            ->assertJsonPath('status', 503)
            ->assertJsonPath('type', 'https://boothpos.local/problems/customer_commerce_disabled')
            ->assertJsonMissingPath('errors');

        $this->assertSame(['type', 'title', 'status', 'detail', 'code', 'request_id'], array_keys($response->json()));
        $this->assertSame(0, CustomerAccount::count());
        $this->assertSame(0, Customer::count());

        // Galat bentuk pun tidak diperiksa lebih dulu: saklar menang atas validasi.
        $this->postJson('/api/v1/customer/auth/register', [])->assertStatus(503);
    }

    public function test_existing_customers_can_still_sign_in_and_read_their_profile_while_off(): void
    {
        $account = $this->makeCustomerAccount(['email' => 'rina@example.test'], ['name' => 'Rina']);

        $token = $this->postJson('/api/v1/customer/auth/login', ['email' => 'rina@example.test', 'password' => 'password'])
            ->assertOk()->json('token');

        $this->bearer($token)->getJson('/api/v1/customer/me')->assertOk()->assertJsonPath('data.id', $account->id);
        $this->bearer($token)->putJson('/api/v1/customer/me', ['name' => 'Rina Baru'])->assertOk();
        $this->bearer($token)->postJson('/api/v1/customer/auth/logout')->assertNoContent();
    }

    public function test_turning_the_feature_on_opens_registration(): void
    {
        $this->enableCustomerCommerce();

        $this->postJson('/api/v1/customer/auth/register', [
            'name' => 'Rina Pelanggan', 'email' => 'rina@example.test', 'password' => 'kata-sandi-uji-123',
        ])->assertCreated();
    }

    public function test_staff_see_the_flag_in_settings_features(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->getJson('/api/v1/settings/features')->assertOk()->assertJsonPath('customer_commerce_enabled', false);

        $this->enableCustomerCommerce();
        $this->getJson('/api/v1/settings/features')->assertOk()->assertJsonPath('customer_commerce_enabled', true);
    }

    public function test_the_flag_accepts_only_boolean_values(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        foreach (['banana', 'ya', 2, '', null, ['x']] as $bad) {
            $this->putSettings([['key' => 'customer_commerce_enabled', 'value' => $bad]])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['settings.0.value']);
        }
        $this->assertFalse(CustomerCommerceGate::enabled());
        $this->assertDatabaseMissing('settings', ['key' => 'customer_commerce_enabled']);

        foreach ([true, 1, '1', 'true'] as $on) {
            $this->putSettings([['key' => 'customer_commerce_enabled', 'value' => $on, 'type' => 'boolean', 'group' => 'customer_commerce']])->assertOk();
            $this->assertTrue(CustomerCommerceGate::enabled(), 'Nilai '.json_encode($on).' harus menyalakan fitur.');
        }

        foreach ([false, 0, '0', 'false'] as $off) {
            $this->putSettings([['key' => 'customer_commerce_enabled', 'value' => $off, 'type' => 'boolean', 'group' => 'customer_commerce']])->assertOk();
            $this->assertFalse(CustomerCommerceGate::enabled(), 'Nilai '.json_encode($off).' harus mematikan fitur.');
        }

        // Tanpa `type` pun hasil bacanya benar (nilai tersimpan sebagai teks).
        $this->putSettings([['key' => 'customer_commerce_enabled', 'value' => true]])->assertOk();
        $this->assertTrue(CustomerCommerceGate::enabled());
        $this->putSettings([['key' => 'customer_commerce_enabled', 'value' => false]])->assertOk();
        $this->assertFalse(CustomerCommerceGate::enabled());
    }

    public function test_instruction_texts_are_validated_as_short_plain_text(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        foreach (['customer_commerce_pickup_instructions', 'customer_commerce_courier_instructions'] as $key) {
            foreach ([str_repeat('a', 501), '<b>Tebal</b>', 'Klik <a href="https://contoh.test">di sini</a>', '<script>alert(1)</script>', 123, ['x'], "nol\0byte"] as $bad) {
                $this->putSettings([['key' => $key, 'value' => $bad]])
                    ->assertStatus(422)
                    ->assertJsonValidationErrors(['settings.0.value']);
            }
            $this->assertDatabaseMissing('settings', ['key' => $key]);

            $text = "Ambil di booth A12 pukul 10-17.\nHarga < 50rb & bawa KTP.";
            $this->putSettings([['key' => $key, 'value' => $text, 'group' => 'customer_commerce']])->assertOk();
            $this->assertDatabaseHas('settings', ['key' => $key, 'value' => $text]);

            $this->putSettings([['key' => $key, 'value' => str_repeat('b', 500)]])->assertOk();
            $this->putSettings([['key' => $key, 'value' => null]])->assertOk();
            $this->assertDatabaseHas('settings', ['key' => $key, 'value' => null]);
        }

        $this->getJson('/api/v1/storefront/config')
            ->assertJsonPath('data.pickup_instructions', null)
            ->assertJsonPath('data.courier_instructions', null);
    }

    public function test_only_roles_with_the_settings_menu_can_flip_the_flag(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->putSettings([['key' => 'customer_commerce_enabled', 'value' => true]])->assertStatus(403);
        $this->assertFalse(CustomerCommerceGate::enabled());
    }

    public function test_the_switch_guards_exactly_the_routes_the_contract_names(): void
    {
        $gated = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || ! in_array('customer.commerce', $route->gatherMiddleware(), true)) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $gated[] = "{$method} {$route->uri()}";
            }
        }

        // CONTRACT §2: saklar menutup etalase (kecuali /config), pendaftaran, tulis alamat, quote dan checkout. Sengaja
        // TIDAK: login, logout, profil, baca alamat, baca/lacak pesanan, dan seluruh rute staf — mematikan saklar
        // adalah jalan mundur yang tidak memutus pelanggan dari pesanan yang sudah masuk. Rute baru di bawah saklar
        // (atau rute yang lepas darinya) harus diputuskan dengan sadar di sini.
        $expected = [
            'GET api/v1/storefront/categories',
            'GET api/v1/storefront/products',
            'GET api/v1/storefront/products/{slug}',
            'POST api/v1/customer/auth/register',
            'POST api/v1/customer/addresses',
            'PUT api/v1/customer/addresses/{addressId}',
            'DELETE api/v1/customer/addresses/{addressId}',
            'POST api/v1/customer/orders/quote',
            'POST api/v1/customer/orders',
        ];
        sort($expected);
        sort($gated);

        $this->assertSame($expected, $gated);
    }
}
