<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\MenuKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 046-hide-events-preorders — modul Event dan Pre-order dihapus dari API dan dari registri menu: semua rute lamanya
 * menjawab 404 (bahkan untuk owner), kunci menunya tak ada, dan peran yang masih menyimpan kunci itu tidak terkunci.
 */
class RemovedModulesRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_old_event_and_preorder_route_answers_404_for_an_owner(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $gone = [
            ['GET', '/events'], ['POST', '/events'], ['GET', '/events/1'], ['PUT', '/events/1'], ['DELETE', '/events/1'], ['PATCH', '/events/1/status'],
            ['GET', '/preorders'], ['POST', '/preorders'], ['GET', '/preorders/1'], ['PUT', '/preorders/1'], ['DELETE', '/preorders/1'],
            ['GET', '/preorders/summary'], ['GET', '/preorders/export'], ['GET', '/preorders/import/template'], ['POST', '/preorders/import'],
            ['POST', '/preorders/bulk-invoices'], ['POST', '/preorders/bulk-email'], ['POST', '/preorders/duplicate'],
            ['PATCH', '/preorders/1/status'], ['PATCH', '/preorders/1/dispatch-status'], ['POST', '/preorders/1/payments'],
            ['DELETE', '/preorders/1/payments/1'], ['PATCH', '/preorders/1/payments/1/confirmation'], ['POST', '/preorders/1/payments/1/verify'],
            ['POST', '/preorders/1/split'], ['POST', '/preorders/1/shipment'], ['GET', '/preorders/1/invoice'], ['POST', '/preorders/1/notifications/resend'],
            ['PATCH', '/shipments/1'], ['GET', '/reports/preorders'], ['GET', '/reports/preorder/export'],
        ];

        foreach ($gone as [$method, $uri]) {
            $this->json($method, "/api/v1{$uri}")->assertNotFound("{$method} {$uri} masih ada");
        }
    }

    public function test_the_menu_registry_has_neither_key_and_the_api_does_not_offer_them(): void
    {
        $this->assertFalse(MenuKeys::isValid('events'));
        $this->assertFalse(MenuKeys::isValid('preorders'));

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $keys = collect($this->getJson('/api/v1/menu-keys')->assertOk()->json())->flatten()->all();

        $this->assertNotContains('events', $keys);
        $this->assertNotContains('preorders', $keys);
    }

    public function test_a_role_cannot_be_saved_with_the_removed_keys(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $this->postJson('/api/v1/roles', ['name' => 'Baru', 'menu_keys' => ['dashboard', 'events']])->assertStatus(422);
        $this->postJson('/api/v1/roles', ['name' => 'Baru 2', 'menu_keys' => ['dashboard', 'preorders']])->assertStatus(422);
    }

    public function test_a_user_whose_role_still_stores_the_old_keys_can_work_and_is_not_locked_out(): void
    {
        $role = Role::factory()->create(['name' => 'Kasir Lama', 'menu_keys' => ['dashboard', 'pos', 'sales', 'events', 'preorders']]);
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->getJson('/api/v1/reports/sales')->assertOk();
        $this->getJson('/api/v1/events')->assertNotFound();
    }
}
