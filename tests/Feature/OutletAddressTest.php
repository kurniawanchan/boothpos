<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 079 / US1 — alamat outlet: disimpan, dibaca ulang, dibatasi 500 karakter, hanya owner/admin yang boleh mengubah. */
class OutletAddressTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_owner_creates_an_outlet_with_address_and_reads_it_back(): void
    {
        $this->actingAsRole('owner');

        $id = $this->postJson('/api/v1/outlets', [
            'code' => 'KD001', 'name' => 'Kedai Satu', 'address' => 'Jl. Melati No. 5, Bandung',
            'contact_phone' => '0812-111', 'contact_email' => 'satu@example.test', 'notes' => 'Buka 08–22',
        ])->assertCreated()->assertJsonPath('address', 'Jl. Melati No. 5, Bandung')->json('id');

        $this->getJson("/api/v1/outlets/{$id}")->assertOk()
            ->assertJsonPath('address', 'Jl. Melati No. 5, Bandung')
            ->assertJsonPath('notes', 'Buka 08–22');
        $this->assertSame('Jl. Melati No. 5, Bandung', $this->getJson('/api/v1/outlets')->json('data.0.address'));
    }

    public function test_an_outlet_without_address_can_be_given_one_and_it_can_be_cleared(): void
    {
        $this->actingAsRole('admin');
        $outlet = Outlet::factory()->create(['address' => null]);

        $this->putJson("/api/v1/outlets/{$outlet->id}", ['name' => $outlet->name, 'address' => 'Jl. Baru 1'])
            ->assertOk()->assertJsonPath('address', 'Jl. Baru 1');
        $this->putJson("/api/v1/outlets/{$outlet->id}", ['name' => $outlet->name, 'address' => null])
            ->assertOk()->assertJsonPath('address', null);
    }

    public function test_an_address_over_the_limit_is_refused_with_a_422_on_address(): void
    {
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/outlets', ['code' => 'ABC', 'name' => 'X', 'address' => str_repeat('a', 501)])
            ->assertStatus(422)->assertJsonValidationErrors('address');
        $this->assertDatabaseMissing('outlets', ['code' => 'ABC']);
    }

    public function test_a_cashier_cannot_create_or_edit_an_outlet(): void
    {
        $outlet = Outlet::factory()->create();
        $this->actingAsRole('cashier');

        $this->postJson('/api/v1/outlets', ['code' => 'ZZZ', 'name' => 'X', 'address' => 'A'])->assertForbidden();
        $this->putJson("/api/v1/outlets/{$outlet->id}", ['name' => 'X', 'address' => 'A'])->assertForbidden();
    }
}
