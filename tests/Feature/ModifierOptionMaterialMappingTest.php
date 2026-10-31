<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Material;
use App\Models\ModifierOptionMaterial;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 087 US1 (T015) — pemetaan bahan per pilihan modifier lewat payload grup modifier (research D2, D3, D10, D11).
 */
class ModifierOptionMaterialMappingTest extends TestCase
{
    use RefreshDatabase;

    private Material $telur;

    private Material $bakso;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'inventory']), 'sanctum');
        $this->telur = Material::factory()->create(['name' => 'Telur', 'unit' => 'butir']);
        $this->bakso = Material::factory()->create(['name' => 'Bakso', 'unit' => 'buah']);
    }

    private function topping(?array $telurMaterials = null, array $baksoExtra = []): array
    {
        $telur = ['name' => 'Telur', 'price_delta' => 3000];
        if ($telurMaterials !== null) {
            $telur['materials'] = $telurMaterials;
        }

        return ['name' => 'Topping', 'selection_type' => 'multiple', 'options' => [
            $telur,
            ['name' => 'Bakso', 'price_delta' => 4000, ...$baksoExtra],
        ]];
    }

    private function create(array $payload): array
    {
        return $this->postJson('/api/v1/modifier-groups', $payload)->assertCreated()->json();
    }

    public function test_mappings_are_saved_and_returned_with_material_details(): void
    {
        $group = $this->create($this->topping([['material_id' => $this->telur->id, 'quantity' => 1]], ['materials' => [['material_id' => $this->bakso->id, 'quantity' => 3]]]));

        $this->assertSame([[
            'material_id' => $this->telur->id, 'code' => $this->telur->code, 'name' => 'Telur', 'unit' => 'butir',
            'quantity' => '1.0000', 'material_active' => true,
        ]], $group['options'][0]['materials']);
        $this->assertSame('3.0000', $group['options'][1]['materials'][0]['quantity']);

        $listed = collect($this->getJson('/api/v1/modifier-groups')->assertOk()->json('data'))->firstWhere('id', $group['id']);
        $this->assertCount(1, $listed['options'][0]['materials']);
    }

    public function test_one_option_may_use_several_materials_with_fractional_quantities(): void
    {
        $group = $this->create(['name' => 'Level Pedas', 'selection_type' => 'single', 'options' => [
            ['name' => 'Extra Pedas', 'price_delta' => 0, 'materials' => [
                ['material_id' => $this->telur->id, 'quantity' => 2], ['material_id' => $this->bakso->id, 'quantity' => 0.125],
            ]],
        ]]);

        $this->assertSame(['2.0000', '0.1250'], collect($group['options'][0]['materials'])->sortBy('material_id')->pluck('quantity')->values()->all());
    }

    public function test_omitting_the_materials_key_keeps_mappings_and_an_empty_list_clears_them(): void
    {
        $group = $this->create($this->topping([['material_id' => $this->telur->id, 'quantity' => 1]]));
        $telurId = $group['options'][0]['id'];
        $baksoId = $group['options'][1]['id'];

        // Klien lama: tidak mengirim `materials` → pemetaan tetap.
        $this->putJson("/api/v1/modifier-groups/{$group['id']}", ['options' => [
            ['id' => $telurId, 'name' => 'Telur', 'price_delta' => 3500],
            ['id' => $baksoId, 'name' => 'Bakso', 'price_delta' => 4000],
        ]])->assertOk()->assertJsonPath('options.0.materials.0.material_id', $this->telur->id);

        $this->putJson("/api/v1/modifier-groups/{$group['id']}", ['options' => [
            ['id' => $telurId, 'name' => 'Telur', 'price_delta' => 3500, 'materials' => []],
            ['id' => $baksoId, 'name' => 'Bakso', 'price_delta' => 4000],
        ]])->assertOk()->assertJsonPath('options.0.materials', []);

        $this->assertSame(0, ModifierOptionMaterial::count());
    }

    public function test_invalid_rows_are_refused_with_field_keys_and_nothing_is_saved(): void
    {
        $inactive = Material::factory()->create(['is_active' => false]);
        $deleted = Material::factory()->create();
        $deleted->delete();
        $demo = ModeGate::runAs('demo', fn () => Material::factory()->create());

        $cases = [
            [['material_id' => $this->telur->id, 'quantity' => 0], 'options.0.materials.0.quantity'],
            [['material_id' => $this->telur->id, 'quantity' => -1], 'options.0.materials.0.quantity'],
            [['material_id' => $this->telur->id, 'quantity' => 'banyak'], 'options.0.materials.0.quantity'],
            [['material_id' => $inactive->id, 'quantity' => 1], 'options.0.materials.0.material_id'],
            [['material_id' => $deleted->id, 'quantity' => 1], 'options.0.materials.0.material_id'],
            [['material_id' => $demo->id, 'quantity' => 1], 'options.0.materials.0.material_id'],
        ];
        foreach ($cases as [$row, $key]) {
            $this->postJson('/api/v1/modifier-groups', $this->topping([$row]))->assertStatus(422)->assertJsonValidationErrors($key);
        }

        $this->postJson('/api/v1/modifier-groups', $this->topping([
            ['material_id' => $this->telur->id, 'quantity' => 1], ['material_id' => $this->telur->id, 'quantity' => 2],
        ]))->assertStatus(422)->assertJsonValidationErrors('options.0.materials.1.material_id');

        $this->assertSame(0, ModifierOptionMaterial::count());
        $this->assertDatabaseMissing('modifier_groups', ['name' => 'Topping']);
    }

    public function test_one_activity_entry_is_written_only_when_mappings_change(): void
    {
        $group = $this->create($this->topping([['material_id' => $this->telur->id, 'quantity' => 1]]));
        $this->assertSame(1, ActivityLog::where('action', 'modifier_materials_changed')->count());

        $same = ['options' => [
            ['id' => $group['options'][0]['id'], 'name' => 'Telur', 'price_delta' => 3000, 'materials' => [['material_id' => $this->telur->id, 'quantity' => '1.0000']]],
            ['id' => $group['options'][1]['id'], 'name' => 'Bakso', 'price_delta' => 4000],
        ]];
        $this->putJson("/api/v1/modifier-groups/{$group['id']}", $same)->assertOk();
        $this->assertSame(1, ActivityLog::where('action', 'modifier_materials_changed')->count());

        $same['options'][0]['materials'][0]['quantity'] = 2;
        $this->putJson("/api/v1/modifier-groups/{$group['id']}", $same)->assertOk();
        $log = ActivityLog::where('action', 'modifier_materials_changed')->latest('id')->first();
        $this->assertSame(2, ActivityLog::where('action', 'modifier_materials_changed')->count());
        $this->assertSame('2.0000', $log->new_values[$group['options'][0]['id']][0]['quantity']);
        $this->assertSame('1.0000', $log->old_values[$group['options'][0]['id']][0]['quantity']);
    }

    public function test_material_detail_lists_users_but_the_list_stays_lean(): void
    {
        $group = $this->create($this->topping([['material_id' => $this->telur->id, 'quantity' => 1]]));

        $this->getJson("/api/v1/materials/{$this->telur->id}")->assertOk()
            ->assertJsonPath('used_by_modifier_options.0.option', 'Telur')
            ->assertJsonPath('used_by_modifier_options.0.group', 'Topping')
            ->assertJsonPath('used_by_modifier_options.0.group_id', $group['id'])
            ->assertJsonPath('used_by_modifier_options.0.quantity', '1.0000');

        $row = collect($this->getJson('/api/v1/materials')->assertOk()->json('data'))->firstWhere('id', $this->telur->id);
        $this->assertArrayNotHasKey('used_by_modifier_options', $row);
    }

    public function test_a_material_used_by_an_active_option_cannot_be_deleted(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $group = $this->create($this->topping([['material_id' => $this->telur->id, 'quantity' => 1]]));

        $this->actingAs($owner, 'sanctum')->deleteJson("/api/v1/materials/{$this->telur->id}")->assertStatus(409);
        $this->assertNotSoftDeleted('materials', ['id' => $this->telur->id]);

        // Pilihan Telur dinonaktifkan (tidak dikirim) → bahan boleh dihapus.
        $this->putJson("/api/v1/modifier-groups/{$group['id']}", ['options' => [
            ['id' => $group['options'][1]['id'], 'name' => 'Bakso', 'price_delta' => 4000],
        ]])->assertOk();
        $this->deleteJson("/api/v1/materials/{$this->telur->id}")->assertNoContent();
    }

    public function test_a_role_without_dining_setup_cannot_change_mappings(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');
        $this->postJson('/api/v1/modifier-groups', $this->topping([['material_id' => $this->telur->id, 'quantity' => 1]]))->assertForbidden();
    }
}
