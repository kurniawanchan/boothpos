<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\LicenseActivation;
use App\Support\MachineFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §3) — gerbang lisensi (423) pada rute pelanggan
 * dan etalase memakai amplop `application/problem+json` dengan kode
 * `installation_locked`; rute staf dan rute lisensi tetap persis seperti
 * sebelumnya (`{message, activated: false}`).
 *
 * TestCase mengaktivasi instalasi secara bawaan; test ini menghapus baris
 * aktivasinya untuk menguji dari keadaan terkunci.
 */
class CustomerInstallationLockedTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    private const PROBLEM_KEYS = ['type', 'title', 'status', 'detail', 'code', 'request_id'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCustomerCommerce();
        LicenseActivation::query()->delete();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function customerRoutes(): array
    {
        return [
            'etalase: config' => ['GET', '/api/v1/storefront/config'],
            'etalase: produk' => ['GET', '/api/v1/storefront/products'],
            'pelanggan: login' => ['POST', '/api/v1/customer/auth/login'],
            'pelanggan: daftar' => ['POST', '/api/v1/customer/auth/register'],
            'pelanggan: profil' => ['GET', '/api/v1/customer/me'],
            'pelanggan: checkout' => ['POST', '/api/v1/customer/orders'],
        ];
    }

    #[DataProvider('customerRoutes')]
    public function test_a_locked_installation_answers_customer_routes_with_the_problem_envelope(string $method, string $uri): void
    {
        $response = $this->withHeader('Accept-Language', 'id')->json($method, $uri, []);

        $response->assertStatus(423)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'installation_locked')
            ->assertJsonPath('status', 423)
            ->assertJsonPath('type', 'https://boothpos.local/problems/installation_locked')
            ->assertJsonPath('title', 'Toko belum diaktifkan')
            ->assertJsonPath('detail', 'Toko ini belum diaktifkan. Silakan coba lagi nanti.')
            ->assertJsonMissingPath('activated')
            ->assertJsonMissingPath('message')
            ->assertJsonMissingPath('errors');

        $this->assertSame(self::PROBLEM_KEYS, array_keys($response->json()));
        $this->assertTrue(Str::isUuid($response->json('request_id')));
        $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-Id'));
    }

    public function test_the_locked_message_follows_accept_language_and_echoes_a_good_request_id(): void
    {
        $response = $this->withHeaders(['Accept-Language' => 'en', 'X-Request-Id' => 'app-lock-0001-abcd'])->getJson('/api/v1/storefront/config');

        $response->assertStatus(423)
            ->assertJsonPath('title', 'The store is not activated')
            ->assertJsonPath('detail', 'This store has not been activated yet. Please try again later.')
            ->assertJsonPath('request_id', 'app-lock-0001-abcd');
        $this->assertSame('app-lock-0001-abcd', $response->headers->get('X-Request-Id'));
    }

    public function test_staff_routes_keep_the_old_locked_body_byte_for_byte(): void
    {
        foreach ([['POST', '/api/v1/auth/login'], ['GET', '/api/v1/vendors'], ['GET', '/api/v1/customer-orders']] as [$method, $uri]) {
            $response = $this->json($method, $uri, []);

            $response->assertStatus(423)->assertExactJson([
                'message' => 'Instalasi ini belum diaktivasi.',
                'activated' => false,
            ]);
            $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
            $this->assertFalse($response->headers->has('X-Request-Id'), 'Rute staf tidak memakai X-Request-Id.');
        }
    }

    public function test_the_license_routes_stay_exempt_from_the_gate(): void
    {
        $this->getJson('/api/v1/license/status')->assertOk()->assertJsonPath('activated', false);
    }

    public function test_an_activated_installation_is_not_locked_for_customer_routes(): void
    {
        LicenseActivation::query()->create([
            'license_id' => 'test-suite', 'issued_to' => 'Test Suite',
            'machine_fingerprint_hash' => Hash::make(MachineFingerprint::current()),
            'activated_at' => now(),
        ]);

        $this->getJson('/api/v1/storefront/config')->assertOk();
    }
}
