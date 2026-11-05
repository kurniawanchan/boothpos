<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 057 — PUT /settings: kunci branding butuh aksi `branding`; kunci lain cukup `edit`. */
class SettingsBrandingActionTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(array $settingsActions): void
    {
        $u = User::factory()->create();
        $u->update(['role_id' => Role::factory()->create(['menu_keys' => ['settings'], 'menu_actions' => ['settings' => $settingsActions]])->id]);
        $this->actingAs($u->fresh(), 'sanctum');
    }

    public function test_edit_only_cannot_change_branding_keys_through_the_generic_endpoint(): void
    {
        $this->actAs(['edit']);

        $this->putJson('/api/v1/settings', ['settings' => [['key' => 'app_name', 'value' => 'Hacked']]])
            ->assertStatus(403)->assertJsonPath('code', 'action_not_allowed')->assertJsonPath('action', 'branding');
        $this->assertDatabaseMissing('settings', ['key' => 'app_name', 'value' => 'Hacked']);

        $this->putJson('/api/v1/settings', ['settings' => [['key' => 'receipt_footer_text', 'value' => 'Terima kasih']]])->assertOk();
    }

    public function test_the_branding_action_allows_branding_keys(): void
    {
        $this->actAs(['edit', 'branding']);

        $this->putJson('/api/v1/settings', ['settings' => [['key' => 'app_name', 'value' => 'Tokoku']]])->assertOk();
    }
}
