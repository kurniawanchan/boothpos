<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 045 Stage 2 (T029) — konteks outlet per permintaan.
 *
 * Aturan yang dikunci di sini (contracts/api-scope.md, D5/D6):
 *  - outlet yang boleh dilihat = penugasan pengguna (Owner/Admin: semua outlet aktif);
 *  - header X-Outlet-Id hanya MENYEMPITKAN dalam daftar itu; di luar itu → 403 outlet_forbidden;
 *  - tanpa header → semua outlet yang boleh dilihat;
 *  - GET /me/scope menyatakan daftar, pilihan, can_see_all, dan multi.
 */
class OutletContextTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private function actAs(User $user): User
    {
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_a_cashier_sees_only_the_outlets_assigned_to_them(): void
    {
        $outlets = $this->makeOutlets(3);
        $cashier = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$outlets[0], $outlets[1]]);
        $this->actAs($cashier);

        $scope = $this->getJson('/api/v1/me/scope')->assertOk();

        $this->assertEquals([$outlets[0]->id, $outlets[1]->id], collect($scope->json('outlets'))->pluck('id')->all());
        $scope->assertJsonPath('can_see_all', false)
            ->assertJsonPath('multi', true)
            ->assertJsonPath('selected', null);
    }

    public function test_an_owner_sees_every_active_outlet_without_assignments(): void
    {
        $outlets = $this->makeOutlets(3);
        $this->actAs(User::factory()->create(['role' => 'owner']));

        $scope = $this->getJson('/api/v1/me/scope')->assertOk();

        $this->assertCount(3, $scope->json('outlets'));
        $scope->assertJsonPath('can_see_all', true)->assertJsonPath('multi', true);
        $this->assertEquals($outlets->pluck('id')->all(), collect($scope->json('outlets'))->pluck('id')->all());
    }

    public function test_the_header_narrows_within_the_permitted_outlets(): void
    {
        $outlets = $this->makeOutlets(3);
        $this->actAs(User::factory()->create(['role' => 'owner']));

        $scope = $this->withHeader('X-Outlet-Id', (string) $outlets[1]->id)
            ->getJson('/api/v1/me/scope')
            ->assertOk();

        $scope->assertJsonPath('selected', $outlets[1]->id);
        $this->assertCount(3, $scope->json('outlets'), 'the selection narrows the filter, not the outlet list');
    }

    public function test_an_outlet_outside_the_permitted_set_is_refused_with_403(): void
    {
        $outlets = $this->makeOutlets(3);
        $cashier = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$outlets[0]]);
        $this->actAs($cashier);

        $this->withHeader('X-Outlet-Id', (string) $outlets[2]->id)
            ->getJson('/api/v1/me/scope')
            ->assertForbidden()
            ->assertJsonPath('code', 'outlet_forbidden');
    }

    public function test_a_non_numeric_outlet_header_is_refused_with_422(): void
    {
        $this->makeOutlets(1);
        $this->actAs(User::factory()->create(['role' => 'owner']));

        $this->withHeader('X-Outlet-Id', 'abc')
            ->getJson('/api/v1/me/scope')
            ->assertStatus(422)
            ->assertJsonPath('code', 'outlet_invalid');
    }

    public function test_without_the_header_nothing_is_selected(): void
    {
        $this->makeOutlets(2);
        $this->actAs(User::factory()->create(['role' => 'owner']));

        $this->getJson('/api/v1/me/scope')->assertOk()->assertJsonPath('selected', null);
    }

    public function test_a_non_admin_without_assignment_sees_no_outlet_and_is_not_multi(): void
    {
        $this->makeOutlets(3);
        $this->actAs(User::factory()->unassigned()->create(['role' => 'cashier']));

        $scope = $this->getJson('/api/v1/me/scope')->assertOk();

        $this->assertSame([], $scope->json('outlets'));
        $scope->assertJsonPath('multi', false)->assertJsonPath('can_see_all', false);
    }

    public function test_inactive_outlets_are_not_permitted(): void
    {
        $outlets = $this->makeOutlets(3);
        $outlets[2]->update(['is_active' => false]);
        $this->actAs(User::factory()->create(['role' => 'owner']));

        $this->getJson('/api/v1/me/scope')
            ->assertOk()
            ->assertJsonCount(2, 'outlets');
    }
}
