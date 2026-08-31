<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Setting;
use App\Models\User;
use App\Support\LicenseGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LicenseGateTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsOwner(): User
    {
        $user = User::factory()->create(['role' => 'owner']);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    private function setMultiArtist(bool $enabled): void
    {
        Setting::updateOrCreate(
            ['key' => 'multi_artist_enabled'],
            ['value' => $enabled ? '1' : '0', 'type' => 'boolean', 'group' => 'licensing']
        );
    }

    // --- Kasus utama: tier Pro membatasi 1 artist -----------------------

    public function test_pro_tier_allows_creating_the_first_artist(): void
    {
        $this->setMultiArtist(false);
        $this->actingAsOwner();

        $response = $this->postJson('/api/v1/artists', ['code' => 'RYU', 'name' => 'Toko Saya']);

        $response->assertCreated();
    }

    public function test_pro_tier_rejects_second_artist_with_clear_message(): void
    {
        $this->setMultiArtist(false);
        $this->actingAsOwner();
        Artist::factory()->create();

        $response = $this->postJson('/api/v1/artists', ['code' => 'ABC', 'name' => 'Artist Kedua']);

        $response->assertStatus(403);
        $this->assertStringContainsString('Pro', $response->json('message'));
        $this->assertStringContainsString('Master', $response->json('message'));
        $this->assertDatabaseCount('artists', 1);
    }

    public function test_master_tier_allows_unlimited_artists(): void
    {
        $this->setMultiArtist(true);
        $this->actingAsOwner();
        Artist::factory()->count(3)->create();

        $response = $this->postJson('/api/v1/artists', ['code' => 'ABC', 'name' => 'Artist Keempat']);

        $response->assertCreated();
        $this->assertDatabaseCount('artists', 4);
    }

    // --- Regresi: pesan 403 untuk peran salah TIDAK tertukar dengan pesan kuota ---

    public function test_wrong_role_still_gets_role_message_not_quota_message(): void
    {
        $this->setMultiArtist(true); // Master aktif, jadi bukan soal kuota
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $response = $this->postJson('/api/v1/artists', ['code' => 'ABC', 'name' => 'Test']);

        $response->assertStatus(403);
        $this->assertStringContainsString('berhak', $response->json('message'));
        $this->assertStringNotContainsString('upgrade', strtolower($response->json('message')));
    }

    // --- Bug yang ditemukan & diperbaiki saat menulis test ini --------

    public function test_string_false_value_is_correctly_treated_as_disabled(): void
    {
        // Regresi untuk bug PHP truthy-string: (bool)"false" === true.
        // Memverifikasi filter_var menangani ini dengan benar.
        Setting::updateOrCreate(
            ['key' => 'multi_artist_enabled'],
            ['value' => 'false', 'type' => 'boolean', 'group' => 'licensing']
        );

        $this->assertFalse(LicenseGate::multiArtistEnabled());
    }

    public function test_missing_setting_defaults_to_disabled_not_enabled(): void
    {
        // Tidak ada baris 'multi_artist_enabled' sama sekali di settings.
        $this->assertFalse(LicenseGate::multiArtistEnabled());
    }

    // --- Endpoint status fitur -----------------------------------------

    public function test_features_endpoint_reflects_pro_tier_state(): void
    {
        $this->setMultiArtist(false);
        $this->actingAsOwner();
        Artist::factory()->create();

        $response = $this->getJson('/api/v1/settings/features');

        $response->assertOk()
            ->assertJsonPath('multi_artist_enabled', false)
            ->assertJsonPath('artist_count', 1)
            ->assertJsonPath('artist_limit_reached', true);
    }

    public function test_upgrading_to_master_immediately_unblocks_creation(): void
    {
        $this->setMultiArtist(false);
        $this->actingAsOwner();
        Artist::factory()->create();

        $this->postJson('/api/v1/artists', ['code' => 'ABC', 'name' => 'Kedua'])->assertStatus(403);

        $this->setMultiArtist(true); // admin upgrade ke Master

        $this->postJson('/api/v1/artists', ['code' => 'ABC', 'name' => 'Kedua'])->assertCreated();
    }
}
