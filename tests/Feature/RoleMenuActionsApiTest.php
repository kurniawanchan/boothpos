<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 057 (US2) — /roles menyimpan & mengembalikan izin aksi; validasi; Owner terkunci; /menu-keys membawa aksi. */
class RoleMenuActionsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $u = User::factory()->create();
        $u->update(['role_id' => Role::where('name', 'Owner')->first()->id]);
        $this->actingAs($u->fresh(), 'sanctum');
    }

    public function test_create_and_update_store_actions_and_the_resource_returns_them_explicitly(): void
    {
        $id = $this->postJson('/api/v1/roles', [
            'name' => 'Finance', 'menu_keys' => ['sales', 'customers'],
            'menu_actions' => ['sales' => ['export'], 'customers' => []],
        ])->assertCreated()
            ->assertJsonPath('menu_actions.sales', ['export'])
            ->assertJsonPath('menu_actions.customers', [])
            ->assertJsonPath('is_locked', false)
            ->assertJsonPath('has_custom_actions', true)
            ->json('id');

        $this->putJson("/api/v1/roles/{$id}", ['menu_actions' => ['sales' => ['void', 'export'], 'customers' => ['create']]])
            ->assertOk()->assertJsonPath('menu_actions.sales', ['void', 'export']);

        $this->assertSame(['sales' => ['void', 'export'], 'customers' => ['create']], Role::find($id)->menu_actions);
    }

    public function test_a_legacy_role_resolves_to_explicit_full_actions_in_the_resource(): void
    {
        $id = $this->postJson('/api/v1/roles', ['name' => 'Old', 'menu_keys' => ['sales']])->assertCreated()
            ->assertJsonPath('has_custom_actions', false)->json('id');

        $this->getJson("/api/v1/roles/{$id}")->assertOk()
            ->assertJsonPath('menu_actions.sales', ['void', 'payment', 'payment_method', 'verify', 'reference', 'export', 'receipts', 'approve']);
    }

    public function test_validation_rejects_unknown_menu_unknown_action_and_a_menu_the_role_does_not_hold(): void
    {
        $base = ['name' => 'X', 'menu_keys' => ['sales']];

        $this->postJson('/api/v1/roles', $base + ['menu_actions' => ['nope' => []]])->assertStatus(422)->assertJsonValidationErrors('menu_actions');
        $this->postJson('/api/v1/roles', $base + ['menu_actions' => ['sales' => ['hack']]])->assertStatus(422)->assertJsonValidationErrors('menu_actions');
        $this->postJson('/api/v1/roles', $base + ['menu_actions' => ['sales' => ['create']]])->assertStatus(422);   // create bukan aksi Sales
        $this->postJson('/api/v1/roles', $base + ['menu_actions' => ['customers' => []]])->assertStatus(422);       // menu tak dimiliki peran
    }

    public function test_update_without_menu_actions_leaves_them_unchanged(): void
    {
        $id = $this->postJson('/api/v1/roles', ['name' => 'Keep', 'menu_keys' => ['sales'], 'menu_actions' => ['sales' => []]])->json('id');

        $this->putJson("/api/v1/roles/{$id}", ['name' => 'Keep 2'])->assertOk();

        $this->assertSame(['sales' => []], Role::find($id)->menu_actions);
    }

    public function test_the_owner_role_cannot_be_restricted_but_can_be_renamed_with_full_actions_resent(): void
    {
        $owner = Role::where('name', 'Owner')->first();

        $this->getJson("/api/v1/roles/{$owner->id}")->assertOk()->assertJsonPath('is_locked', true);
        $this->putJson("/api/v1/roles/{$owner->id}", ['menu_actions' => ['sales' => []]])->assertStatus(422);
        $this->assertNull($owner->fresh()->menu_actions);

        $full = $this->getJson("/api/v1/roles/{$owner->id}")->json('menu_actions');
        $this->putJson("/api/v1/roles/{$owner->id}", ['menu_actions' => $full])->assertOk();
    }

    public function test_the_owner_role_cannot_be_renamed_or_deleted_which_would_bypass_its_lock(): void
    {
        $owner = Role::where('name', 'Owner')->first();

        $this->putJson("/api/v1/roles/{$owner->id}", ['name' => 'Boss'])->assertStatus(422);
        $this->assertSame('Owner', $owner->fresh()->name);
        $this->deleteJson("/api/v1/roles/{$owner->id}")->assertStatus(422);
        $this->assertNotNull(Role::find($owner->id));
        // mengirim ulang nama yang SAMA tetap boleh (editor mengirim nama apa adanya)
        $this->putJson("/api/v1/roles/{$owner->id}", ['name' => 'Owner'])->assertOk();
    }

    public function test_menu_keys_endpoint_carries_the_actions_of_each_menu(): void
    {
        $data = collect($this->getJson('/api/v1/menu-keys')->assertOk()->json('data'));

        $sales = $data->firstWhere('key', 'sales');
        $this->assertSame('void', $sales['actions'][0]['key']);
        $this->assertFalse($sales['actions'][0]['standard']);
        $this->assertNotEmpty($sales['actions'][0]['label']);
        $customers = $data->firstWhere('key', 'customers');
        $this->assertSame(['create', 'edit', 'delete'], array_slice(array_column($customers['actions'], 'key'), 0, 3));
        $this->assertTrue($customers['actions'][0]['standard']);
        $this->assertSame([], $data->firstWhere('key', 'dashboard')['actions']);
    }

    public function test_changes_are_audit_logged_with_old_and_new_actions(): void
    {
        $id = $this->postJson('/api/v1/roles', ['name' => 'Aud', 'menu_keys' => ['sales'], 'menu_actions' => ['sales' => []]])->json('id');

        $this->putJson("/api/v1/roles/{$id}", ['menu_actions' => ['sales' => ['void']]])->assertOk();

        $log = \DB::table('activity_logs')->where('entity_type', 'Role')->where('entity_id', $id)->where('action', 'updated')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(['sales' => ['void']], json_decode((string) $log->new_values, true)['menu_actions']);
        $this->assertSame(['sales' => []], json_decode((string) $log->old_values, true)['menu_actions']);
    }
}
