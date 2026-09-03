<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeAndReceiptSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsOwner(): User
    {
        $user = User::factory()->create(['role' => 'owner']);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_owner_can_set_a_valid_theme_accent_color(): void
    {
        $this->actingAsOwner();

        $this->putJson('/api/v1/settings', [
            'settings' => [['key' => 'theme_accent_color', 'value' => '#c0392b', 'type' => 'string', 'group' => 'appearance']],
        ])->assertOk();

        $this->getJson('/api/v1/settings/features')->assertJsonPath('theme_accent_color', '#c0392b');
    }

    public function test_an_invalid_hex_color_is_rejected(): void
    {
        $this->actingAsOwner();

        $this->putJson('/api/v1/settings', [
            'settings' => [['key' => 'theme_accent_color', 'value' => 'not-a-color', 'type' => 'string', 'group' => 'appearance']],
        ])->assertStatus(422);
    }

    public function test_owner_can_set_receipt_footer_text_and_toggle_the_logo(): void
    {
        $this->actingAsOwner();

        $this->putJson('/api/v1/settings', [
            'settings' => [
                ['key' => 'receipt_footer_text', 'value' => 'Terima kasih sudah berbelanja!', 'type' => 'string', 'group' => 'receipt'],
                ['key' => 'receipt_show_logo', 'value' => false, 'type' => 'boolean', 'group' => 'receipt'],
            ],
        ])->assertOk();

        $features = $this->getJson('/api/v1/settings/features');
        $features->assertJsonPath('receipt_footer_text', 'Terima kasih sudah berbelanja!')
            ->assertJsonPath('receipt_show_logo', false);
    }

    public function test_a_role_without_settings_access_cannot_change_theme_or_receipt_settings(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');

        $this->putJson('/api/v1/settings', [
            'settings' => [['key' => 'theme_accent_color', 'value' => '#2f9e6e', 'type' => 'string', 'group' => 'appearance']],
        ])->assertStatus(403);
    }
}
