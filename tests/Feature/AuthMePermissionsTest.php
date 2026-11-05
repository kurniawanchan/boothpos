<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 057 — GET /auth/me membawa `permissions` hasil Role::resolvedActions() (satu sumber dengan server). */
class AuthMePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function me(array $menus, ?array $actions): array
    {
        $u = User::factory()->create();
        $u->update(['role_id' => Role::factory()->create(['menu_keys' => $menus, 'menu_actions' => $actions])->id]);
        $this->actingAs($u->fresh(), 'sanctum');

        return $this->getJson('/api/v1/auth/me')->assertOk()->json();
    }

    public function test_a_full_role_lists_every_action_of_its_menus(): void
    {
        $json = $this->me(['sales', 'pos'], null);

        $this->assertSame(['view', 'void', 'payment', 'payment_method', 'verify', 'reference', 'export', 'receipts'], $json['permissions']['sales']);
        $this->assertSame(['view', 'create', 'draft'], $json['permissions']['pos']);
        $this->assertSame(['sales', 'pos'], $json['menu_keys']);
    }

    public function test_a_view_only_role_gets_only_view_and_menus_it_lacks_are_absent(): void
    {
        $json = $this->me(['sales'], ['sales' => []]);

        $this->assertSame(['view'], $json['permissions']['sales']);
        $this->assertArrayNotHasKey('pos', $json['permissions']);
    }

    public function test_permissions_match_what_the_server_enforces(): void
    {
        $json = $this->me(['sales'], ['sales' => ['verify']]);

        $this->assertSame(['view', 'verify'], $json['permissions']['sales']);
    }
}
