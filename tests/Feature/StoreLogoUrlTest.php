<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 023 (US4, FR-011/FR-012) — URL logo toko dikirim server. Dulunya satu berkas dengan tes ketersediaan event
 * (dihapus oleh 046 bersama event); isi tes logo tidak berubah.
 */
class StoreLogoUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    public function test_settings_index_returns_resolved_store_logo_url(): void
    {
        Storage::fake('public');
        $path = UploadedFile::fake()->image('logo.png')->store('store-logo', 'public');
        Setting::updateOrCreate(['key' => 'store_logo_path'], ['value' => $path, 'type' => 'string', 'group' => 'receipt']);

        $response = $this->getJson('/api/v1/settings');

        $response->assertOk();
        $this->assertStringContainsString($path, $response->json('store_logo_url'));
    }

    public function test_settings_index_returns_null_store_logo_url_when_unset(): void
    {
        $response = $this->getJson('/api/v1/settings');

        $response->assertOk()->assertJsonPath('store_logo_url', null);
    }

    public function test_upload_store_logo_response_includes_resolved_url(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('logo.png');

        $response = $this->postJson('/api/v1/settings/store-logo', ['image' => $file]);

        $response->assertOk();
        $this->assertNotNull($response->json('store_logo_url'));
        $this->assertStringContainsString('store-logo/', $response->json('store_logo_url'));
    }
}
