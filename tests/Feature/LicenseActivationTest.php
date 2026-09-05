<?php

namespace Tests\Feature;

use App\Models\LicenseActivation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LicenseActivationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Keypair Ed25519 SEKALI PAKAI, di-generate segar setiap test
     * (bukan konstanta tetap) — kunci privatnya hidup hanya di memori
     * proses test ini, tidak pernah ditulis ke berkas apa pun, apalagi
     * ke source control. config('license.public_key') ditimpa ke kunci
     * publik pasangannya supaya LicenseSigning::verify() memverifikasi
     * terhadap keypair test ini, bukan default dev di config/license.php
     * (lihat komentar LicenseSigning — desain ini justru dibuat SUPAYA
     * test tidak pernah perlu meng-commit kunci privat apa pun).
     */
    private string $testSecretKey;

    protected function setUp(): void
    {
        parent::setUp();

        $keypair = sodium_crypto_sign_keypair();
        $this->testSecretKey = sodium_crypto_sign_secretkey($keypair);
        config(['license.public_key' => base64_encode(sodium_crypto_sign_publickey($keypair))]);

        // Tests\TestCase::setUp() sekarang mengaktivasi setiap test
        // secara default (lihat komentar di sana) — test file INI
        // secara khusus menguji perilaku gerbang lisensi itu sendiri,
        // jadi harus mulai dari keadaan BELUM teraktivasi.
        LicenseActivation::query()->delete();
    }

    private function buildLicenseKey(array $overrides = []): string
    {
        $payload = array_merge([
            'license_id' => 'test-license-1',
            'issued_to' => 'Test Store',
            'issued_at' => now()->toIso8601String(),
        ], $overrides);

        $payloadB64 = base64_encode(json_encode($payload));
        $signature = sodium_crypto_sign_detached($payloadB64, $this->testSecretKey);

        return $payloadB64.'.'.base64_encode($signature);
    }

    public function test_status_reports_not_activated_by_default(): void
    {
        $this->getJson('/api/v1/license/status')->assertOk()->assertJsonPath('activated', false);
    }

    public function test_valid_license_key_activates_the_installation(): void
    {
        $response = $this->postJson('/api/v1/license/activate', ['license_key' => $this->buildLicenseKey()]);

        $response->assertOk()->assertJsonPath('activated', true);
        $this->getJson('/api/v1/license/status')->assertJsonPath('activated', true);
        $this->assertDatabaseCount('license_activations', 1);
    }

    public function test_invalid_license_key_is_rejected_and_installation_stays_locked(): void
    {
        $response = $this->postJson('/api/v1/license/activate', ['license_key' => 'garbage.notavalidkey']);

        $response->assertStatus(422)->assertJsonValidationErrors('license_key');
        $this->getJson('/api/v1/license/status')->assertJsonPath('activated', false);
        $this->assertDatabaseCount('license_activations', 0);
    }

    public function test_tampered_payload_is_rejected(): void
    {
        // Payload valid tapi ditandatangani untuk field yang berbeda dari
        // yang sebenarnya dikirim — mensimulasikan payload yang diotak-atik
        // setelah ditandatangani.
        $validKey = $this->buildLicenseKey();
        [$payloadB64, $sig] = explode('.', $validKey, 2);
        $tamperedPayload = base64_encode(str_replace('Test Store', 'Different Store!', base64_decode($payloadB64)));

        $response = $this->postJson('/api/v1/license/activate', ['license_key' => "{$tamperedPayload}.{$sig}"]);

        $response->assertStatus(422);
    }

    public function test_every_other_route_is_locked_while_not_activated(): void
    {
        $this->postJson('/api/v1/auth/login', ['username' => 'owner', 'password' => 'password123'])
            ->assertStatus(423)
            ->assertJsonPath('activated', false);

        $this->getJson('/api/v1/vendors')->assertStatus(423);
    }

    public function test_activated_installation_allows_login_and_normal_routes(): void
    {
        $this->postJson('/api/v1/license/activate', ['license_key' => $this->buildLicenseKey()])->assertOk();

        User::factory()->create(['role' => 'owner', 'username' => 'owner_for_license_test']);

        $this->postJson('/api/v1/auth/login', ['username' => 'owner_for_license_test', 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_corrupted_activation_record_resolves_to_locked_not_activated(): void
    {
        $this->postJson('/api/v1/license/activate', ['license_key' => $this->buildLicenseKey()])->assertOk();

        // Mensimulasikan baris yang rusak/tidak cocok — mis. disalin dari
        // mesin lain (research.md R7) — hash fingerprint tidak akan
        // pernah cocok dengan Hash::check apa pun yang valid.
        LicenseActivation::query()->update(['machine_fingerprint_hash' => Hash::make('some-other-machine-fingerprint')]);

        $this->getJson('/api/v1/license/status')->assertJsonPath('activated', false);
        $this->postJson('/api/v1/auth/login', ['username' => 'owner', 'password' => 'password123'])
            ->assertStatus(423);
    }
}
