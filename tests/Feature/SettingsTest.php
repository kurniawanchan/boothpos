<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\LicenseGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_owner_can_list_settings(): void
    {
        $this->actingAsRole('owner');
        Setting::updateOrCreate(['key' => 'store_name'], ['value' => 'Toko Saya', 'type' => 'string', 'group' => 'receipt']);

        $response = $this->getJson('/api/v1/settings');

        $response->assertOk();
        $this->assertContains('store_name', collect($response->json('data'))->pluck('key'));
    }

    public function test_cashier_cannot_list_settings(): void
    {
        $this->actingAsRole('cashier');

        $this->getJson('/api/v1/settings')->assertStatus(403);
    }

    public function test_owner_can_update_store_identity_settings_in_bulk(): void
    {
        $this->actingAsRole('owner');

        $response = $this->putJson('/api/v1/settings', [
            'settings' => [
                ['key' => 'store_name', 'value' => 'Merch Corner', 'type' => 'string', 'group' => 'receipt'],
                ['key' => 'store_contact', 'value' => '0812-1111-2222', 'type' => 'string', 'group' => 'receipt'],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('settings', ['key' => 'store_name', 'value' => 'Merch Corner']);
        $this->assertDatabaseHas('settings', ['key' => 'store_contact', 'value' => '0812-1111-2222']);
    }

    public function test_admin_can_upgrade_license_from_pro_to_master(): void
    {
        // Ini gap yang secara eksplisit disebut README — sebelumnya hanya
        // bisa lewat Setting::updateOrCreate() langsung (tinker/seeder).
        Setting::updateOrCreate(
            ['key' => 'multi_artist_enabled'],
            ['value' => '0', 'type' => 'boolean', 'group' => 'licensing']
        );
        $this->assertFalse(LicenseGate::multiArtistEnabled());

        $this->actingAsRole('admin');

        $this->putJson('/api/v1/settings', [
            'settings' => [
                ['key' => 'multi_artist_enabled', 'value' => true, 'type' => 'boolean', 'group' => 'licensing'],
            ],
        ])->assertOk();

        $this->assertTrue(LicenseGate::multiArtistEnabled());
    }

    public function test_cashier_cannot_update_settings(): void
    {
        $this->actingAsRole('cashier');

        $this->putJson('/api/v1/settings', [
            'settings' => [['key' => 'store_name', 'value' => 'Hacked']],
        ])->assertStatus(403);

        $this->assertDatabaseMissing('settings', ['key' => 'store_name']);
    }

    public function test_updating_a_setting_requires_the_value_key_to_be_present(): void
    {
        $this->actingAsRole('owner');

        $this->putJson('/api/v1/settings', [
            'settings' => [['key' => 'store_name']],
        ])->assertStatus(422)->assertJsonValidationErrors('settings.0.value');
    }

    // --- User Story 3: profil toko (T042) --------------------------------

    public function test_owner_can_save_store_profile_fields_via_bulk_update(): void
    {
        $this->actingAsRole('owner');

        $response = $this->putJson('/api/v1/settings', [
            'settings' => [
                ['key' => 'store_address', 'value' => 'Jl. Merdeka No. 1, Jakarta', 'type' => 'string', 'group' => 'receipt'],
                ['key' => 'store_contact_person', 'value' => 'Budi Santoso', 'type' => 'string', 'group' => 'receipt'],
                ['key' => 'store_contact_phone', 'value' => '0812-3456-7890', 'type' => 'string', 'group' => 'receipt'],
                ['key' => 'store_contact_email', 'value' => 'toko@contoh.com', 'type' => 'string', 'group' => 'receipt'],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('settings', ['key' => 'store_address', 'value' => 'Jl. Merdeka No. 1, Jakarta']);
        $this->assertDatabaseHas('settings', ['key' => 'store_contact_person', 'value' => 'Budi Santoso']);
        $this->assertDatabaseHas('settings', ['key' => 'store_contact_phone', 'value' => '0812-3456-7890']);
        $this->assertDatabaseHas('settings', ['key' => 'store_contact_email', 'value' => 'toko@contoh.com']);
    }

    public function test_invalid_store_contact_email_format_is_rejected(): void
    {
        $this->actingAsRole('owner');

        $response = $this->putJson('/api/v1/settings', [
            'settings' => [
                ['key' => 'store_contact_email', 'value' => 'bukan-email', 'type' => 'string', 'group' => 'receipt'],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('settings.0.value');
        $this->assertDatabaseMissing('settings', ['key' => 'store_contact_email']);
    }

    public function test_owner_can_upload_store_logo(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->actingAsRole('owner');

        $file = \Illuminate\Http\UploadedFile::fake()->image('logo.png', 200, 200)->size(100);

        $response = $this->postJson('/api/v1/settings/store-logo', ['image' => $file]);

        $response->assertOk();
        $setting = Setting::where('key', 'store_logo_path')->first();
        $this->assertNotNull($setting);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($setting->value);
    }

    public function test_uploading_non_image_as_store_logo_is_rejected(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->actingAsRole('owner');

        $file = \Illuminate\Http\UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf');

        $this->postJson('/api/v1/settings/store-logo', ['image' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
        $this->assertDatabaseMissing('settings', ['key' => 'store_logo_path']);
    }

    public function test_uploading_oversized_store_logo_is_rejected(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->actingAsRole('owner');

        // MAX_KILOBYTES = 5120 (5 MB) — kirim berkas yang melebihinya.
        $file = \Illuminate\Http\UploadedFile::fake()->image('logo.png')->size(6000);

        $this->postJson('/api/v1/settings/store-logo', ['image' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
        $this->assertDatabaseMissing('settings', ['key' => 'store_logo_path']);
    }

    public function test_cashier_cannot_upload_store_logo(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->actingAsRole('cashier');

        $file = \Illuminate\Http\UploadedFile::fake()->image('logo.png');

        $this->postJson('/api/v1/settings/store-logo', ['image' => $file])->assertStatus(403);
    }

    public function test_updating_settings_writes_an_activity_log_with_old_and_new_values(): void
    {
        $owner = $this->actingAsRole('owner');
        Setting::updateOrCreate(['key' => 'store_name'], ['value' => 'Lama', 'type' => 'string', 'group' => 'receipt']);

        $this->putJson('/api/v1/settings', [
            'settings' => [['key' => 'store_name', 'value' => 'Baru', 'type' => 'string', 'group' => 'receipt']],
        ])->assertOk();

        $log = \App\Models\ActivityLog::where('entity_type', 'Setting')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($owner->id, $log->user_id);
        $this->assertSame('updated', $log->action);
        $this->assertSame('Lama', $log->old_values['value']);
        $this->assertSame('Baru', $log->new_values['value']);
    }
}
