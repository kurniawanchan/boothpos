<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 072 — logo toko terbaca SEMUA peran lewat features (GET /settings hanya owner/admin), untuk sidebar dan favicon. */
class SettingsFeaturesStoreLogoTest extends TestCase
{
    use RefreshDatabase;

    public function test_logo_url_is_null_when_no_logo_was_uploaded(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $json = $this->getJson('/api/v1/settings/features')->assertOk()->json();

        $this->assertArrayHasKey('store_logo_url', $json);
        $this->assertNull($json['store_logo_url']);
    }

    public function test_a_cashier_reads_the_resolved_logo_url(): void
    {
        Setting::updateOrCreate(['key' => 'store_logo_path'], ['value' => 'store-logo/abc123.png', 'type' => 'string']);
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $url = $this->getJson('/api/v1/settings/features')->assertOk()->json('store_logo_url');

        $this->assertStringEndsWith('/storage/store-logo/abc123.png', $url);
    }
}
