<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 057 — ekspor/impor master data per entitas: aksi `export`/`import` pada menu pemilik entitas. */
class MasterDataActionsTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(array $menus, ?array $actions): void
    {
        $u = User::factory()->create();
        $u->update(['role_id' => Role::factory()->create(['menu_keys' => $menus, 'menu_actions' => $actions])->id]);
        $this->actingAs($u->fresh(), 'sanctum');
    }

    public function test_export_needs_the_export_action_on_the_entitys_menu(): void
    {
        $this->actAs(['products', 'vendors'], ['products' => ['export'], 'vendors' => []]);

        $this->get('/api/v1/exports/products')->assertOk();
        $this->getJson('/api/v1/exports/vendors')->assertStatus(403)->assertJsonPath('code', 'action_not_allowed');
        $this->getJson('/api/v1/exports/bom')->assertOk();          // bom ikut menu products
    }

    public function test_import_template_and_upload_need_import_on_every_held_master_data_menu(): void
    {
        $this->actAs(['products', 'vendors'], ['products' => ['import'], 'vendors' => []]);
        $this->getJson('/api/v1/imports/master-data/template')->assertStatus(403)->assertJsonPath('code', 'action_not_allowed');
        $this->postJson('/api/v1/imports/master-data', [])->assertStatus(403)->assertJsonPath('code', 'action_not_allowed');

        $this->actAs(['products', 'vendors'], ['products' => ['import'], 'vendors' => ['import']]);
        $this->get('/api/v1/imports/master-data/template')->assertOk();
    }

    public function test_full_access_roles_keep_working_exactly_as_before(): void
    {
        $this->actAs(['products', 'vendors', 'stock'], null);

        $this->get('/api/v1/exports/stock')->assertOk();
        $this->get('/api/v1/imports/master-data/template')->assertOk();
    }
}
