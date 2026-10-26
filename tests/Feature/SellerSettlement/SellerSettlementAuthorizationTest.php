<?php

namespace Tests\Feature\SellerSettlement;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSettlementFixtures;
use Tests\TestCase;

/**
 * 076 (US2/FR-006) — modul hanya untuk pemegang menu 'settlements'
 * (Owner/Admin). Setiap endpoint diperiksa: kasir/inventory 403, tanpa
 * login 401, mode data lain 404.
 */
class SellerSettlementAuthorizationTest extends TestCase
{
    use BuildsSettlementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSettlementWorld();
    }

    /** @return list<array{0: string, 1: string, 2: array}> */
    private function endpoints(int $id, int $adjId): array
    {
        $adj = ['kind' => 'deduction', 'label' => 'L', 'reason' => 'R', 'amount' => '1'];

        return [
            ['GET', '/api/v1/settlements', []],
            ['GET', "/api/v1/settlements/preview?event_id={$this->event->id}&artist_id=1", []],
            ['POST', '/api/v1/settlements', ['event_id' => $this->event->id, 'artist_id' => 1]],
            ['GET', "/api/v1/settlements/{$id}", []],
            ['POST', "/api/v1/settlements/{$id}/refresh", []],
            ['DELETE', "/api/v1/settlements/{$id}", []],
            ['POST', "/api/v1/settlements/{$id}/adjustments", $adj],
            ['PUT', "/api/v1/settlements/{$id}/adjustments/{$adjId}", $adj],
            ['DELETE', "/api/v1/settlements/{$id}/adjustments/{$adjId}", []],
            ['POST', "/api/v1/settlements/{$id}/finalize", []],
            ['POST', "/api/v1/settlements/{$id}/reopen", []],
        ];
    }

    private function fixture(): array
    {
        [$a, $va] = $this->seller('AAA', 10000);
        $this->posSale([[$va, 1]]);
        $id = $this->createDraft($a);
        $adjId = $this->postJson("/api/v1/settlements/{$id}/adjustments", [
            'kind' => 'deduction', 'label' => 'L', 'reason' => 'R', 'amount' => '1',
        ])->json('data.adjustments.0.id');

        return [$id, $adjId];
    }

    public function test_cashier_and_inventory_are_denied_on_every_endpoint(): void
    {
        [$id, $adjId] = $this->fixture();

        foreach (['cashier', 'inventory'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum');
            foreach ($this->endpoints($id, $adjId) as [$method, $uri, $payload]) {
                $this->json($method, $uri, $payload)->assertForbidden("{$role} harus 403 di {$method} {$uri}");
            }
        }

        $this->assertSame('draft', DB::table('seller_settlements')->value('status'), 'tidak ada yang berubah');
    }

    public function test_the_default_owner_and_admin_roles_carry_the_menu_key(): void
    {
        foreach (['owner', 'admin'] as $role) {
            $this->assertTrue(User::factory()->create(['role' => $role])->canAccessMenu('settlements'), $role);
        }
        foreach (['cashier', 'inventory'] as $role) {
            $this->assertFalse(User::factory()->create(['role' => $role])->canAccessMenu('settlements'), $role);
        }
    }

    public function test_an_admin_can_use_the_module(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');

        $this->getJson('/api/v1/settlements')->assertOk();
    }

    public function test_guests_get_401(): void
    {
        [$id, $adjId] = $this->fixture();
        $this->app['auth']->forgetGuards();

        foreach ($this->endpoints($id, $adjId) as [$method, $uri, $payload]) {
            $this->json($method, $uri, $payload)->assertUnauthorized("tamu harus 401 di {$method} {$uri}");
        }
    }

    public function test_a_settlement_of_the_other_data_mode_is_not_found(): void
    {
        [$id] = $this->fixture();
        DB::table('seller_settlements')->where('id', $id)->update(['data_mode' => 'demo']);

        $this->getJson("/api/v1/settlements/{$id}")->assertNotFound();
        $this->postJson("/api/v1/settlements/{$id}/finalize")->assertNotFound();
        $this->deleteJson("/api/v1/settlements/{$id}")->assertNotFound();
        $this->getJson('/api/v1/settlements')->assertOk()->assertJsonPath('meta.total', 0);
    }
}
