<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 028 — matriks peran x endpoint (FR-038, FR-039). Menu-key diperiksa di
 * SERVER; layar hanya mencerminkannya. Diperluas per user story.
 */
class TabPermissionsTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private function userWithKeys(array $keys): User
    {
        return User::factory()->create(['role_id' => Role::factory()->create(['menu_keys' => $keys])->id]);
    }

    /** @return array<string, User> peran TANPA kunci `floor` */
    private function rolesWithoutFloor(): array
    {
        return [
            'inventory (default)' => User::factory()->create(['role' => 'inventory']),
            'kitchen only' => $this->userWithKeys(['kitchen']),
            'dining_setup only' => $this->userWithKeys(['dining_setup']),
            'no restaurant keys' => $this->userWithKeys(['dashboard', 'pos']),
        ];
    }

    public function test_roles_without_floor_are_refused_on_every_tab_endpoint(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $session = $this->makeShift($owner);
        $tab = $this->makeOpenOrder($session, $owner);
        $line = $this->addLineTo($tab, $this->makeVariant());

        foreach ($this->rolesWithoutFloor() as $label => $user) {
            $this->actingAs($user, 'sanctum');

            $this->postJson('/api/v1/tabs', ['session_id' => $session->id])->assertForbidden("POST /tabs ($label)");
            $this->getJson('/api/v1/tabs')->assertForbidden("GET /tabs ($label)");
            $this->getJson("/api/v1/tabs/{$tab->id}")->assertForbidden("GET /tabs/id ($label)");
            $this->deleteJson("/api/v1/tabs/{$tab->id}")->assertForbidden("DELETE /tabs/id ($label)");
            $this->postJson("/api/v1/tabs/{$tab->id}/items", ['variant_id' => 1, 'qty' => 1])->assertForbidden("add line ($label)");
            $this->patchJson("/api/v1/tabs/{$tab->id}/items/{$line->id}", ['qty' => 2])->assertForbidden("edit line ($label)");
            $this->deleteJson("/api/v1/tabs/{$tab->id}/items/{$line->id}")->assertForbidden("remove line ($label)");
            $this->postJson("/api/v1/tabs/{$tab->id}/payments", ['client_ref' => $this->newLocalRef(), 'payments' => [['method' => 'cash', 'amount' => 1]]])->assertForbidden("pay ($label)");
            $this->postJson("/api/v1/tabs/{$tab->id}/handover", ['session_id' => $session->id])->assertForbidden("handover ($label)");
        }
    }

    public function test_a_cashier_can_run_the_whole_tab_flow_but_not_hand_over(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');
        $session = $this->makeShift($cashier);
        $v = $this->makeVariant(price: 10000);

        $tab = $this->apiOpenTab($session->id)->assertCreated()->json('id');
        $this->apiAddLine($tab, $v->id)->assertCreated();
        $this->getJson("/api/v1/tabs/{$tab}")->assertOk();
        $this->postJson("/api/v1/tabs/{$tab}/handover", ['session_id' => $session->id])->assertForbidden();
        $this->apiPay($tab, [['method' => 'cash', 'amount' => 10000]])->assertOk()->assertJsonPath('status', 'completed');
    }

    public function test_owner_and_admin_can_use_the_tab_endpoints(): void
    {
        foreach (['owner', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user, 'sanctum');

            $this->apiOpenTab($this->makeShift($user)->id)->assertCreated();
        }
    }

    // --- dapur / stasiun (US4) ----------------------------------------------------

    public function test_the_default_cashier_role_can_send_cook_serve_and_read_stations(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');
        $station = $this->makeStation('Dapur');
        $tab = $this->apiOpenTab($this->makeShift($cashier)->id)->json('id');
        $this->apiAddLine($tab, $this->makeMenuItem(categoryStation: $station)->id)->assertCreated();

        $this->getJson('/api/v1/stations')->assertOk();
        $this->postJson("/api/v1/tabs/{$tab}/send")->assertOk();
        $this->getJson("/api/v1/stations/{$station->id}/tickets")->assertOk()->assertJsonCount(1, 'tickets');

        $item = \App\Models\KitchenTicketItem::first();
        $this->patchJson("/api/v1/ticket-items/{$item->id}/status", ['status' => 'preparing'])->assertOk();
        $this->getJson("/api/v1/tickets/{$item->kitchen_ticket_id}")->assertOk();

        // …tetapi bukan pengaturan restoran.
        $this->postJson('/api/v1/stations', ['name' => 'X'])->assertForbidden();
    }

    public function test_a_kitchen_only_role_cooks_but_cannot_send_or_take_payment(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');
        $station = $this->makeStation('Dapur');
        $tab = $this->apiOpenTab($this->makeShift($owner)->id)->json('id');
        $this->apiAddLine($tab, $this->makeMenuItem(categoryStation: $station)->id)->assertCreated();

        $cook = $this->userWithKeys(['kitchen']);
        $this->actingAs($cook, 'sanctum');
        $this->postJson("/api/v1/tabs/{$tab}/send")->assertForbidden();
        $this->apiPay($tab, [['method' => 'cash', 'amount' => 1000]])->assertForbidden();

        $this->actingAs($owner, 'sanctum');
        $this->postJson("/api/v1/tabs/{$tab}/send")->assertOk();

        $this->actingAs($cook, 'sanctum');
        $item = \App\Models\KitchenTicketItem::first();
        $this->getJson("/api/v1/stations/{$station->id}/tickets")->assertOk();
        $this->patchJson("/api/v1/ticket-items/{$item->id}/status", ['status' => 'preparing'])->assertOk();
    }

    public function test_a_role_with_only_dining_setup_cannot_use_any_kitchen_endpoint(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');
        $station = $this->makeStation('Dapur');
        $item = $this->makeTicketItem([], $station);

        $setupOnly = User::factory()->create(['role' => 'inventory']); // default: dining_setup, tanpa floor/kitchen
        $this->actingAs($setupOnly, 'sanctum');

        $this->getJson("/api/v1/stations/{$station->id}/tickets")->assertForbidden();
        $this->getJson("/api/v1/tickets/{$item->kitchen_ticket_id}")->assertForbidden();
        $this->patchJson("/api/v1/ticket-items/{$item->id}/status", ['status' => 'preparing'])->assertForbidden();
        // Mengatur stasiun tetap boleh: itu pekerjaan master data.
        $this->postJson('/api/v1/stations', ['name' => 'Bar'])->assertCreated();
    }


    // --- matriks lengkap peran × endpoint (T153) -----------------------------------------

    /** @return array<string, User> */
    private function allRoles(): array
    {
        return [
            'owner' => User::factory()->create(['role' => 'owner']),
            'admin' => User::factory()->create(['role' => 'admin']),
            'cashier' => User::factory()->create(['role' => 'cashier']),
            'inventory' => User::factory()->create(['role' => 'inventory']),
            'kitchen-only' => $this->userWithKeys(['kitchen']),
            'floor-only' => $this->userWithKeys(['floor']),
            'setup-only' => $this->userWithKeys(['dining_setup']),
            'none' => $this->userWithKeys(['dashboard', 'pos']),
        ];
    }

    public function test_read_endpoints_are_open_to_exactly_the_agreed_roles(): void
    {
        $matrix = [
            '/api/v1/floor' => ['owner', 'admin', 'cashier', 'floor-only'],
            '/api/v1/tabs' => ['owner', 'admin', 'cashier', 'floor-only'],
            '/api/v1/dining-tables' => ['owner', 'admin', 'inventory', 'setup-only'],
            '/api/v1/modifier-groups' => ['owner', 'admin', 'inventory', 'setup-only'],
            // Daftar stasiun juga terbuka untuk layar dapur.
            '/api/v1/stations' => ['owner', 'admin', 'cashier', 'inventory', 'kitchen-only', 'setup-only'],
        ];
        $roles = $this->allRoles();
        $wrong = [];

        foreach ($matrix as $url => $allowed) {
            foreach ($roles as $name => $user) {
                $this->actingAs($user, 'sanctum');
                $expected = in_array($name, $allowed, true) ? 200 : 403;
                $actual = $this->getJson($url)->getStatusCode();
                if ($actual !== $expected) {
                    $wrong[] = "$url as $name: expected $expected, got $actual";
                }
            }
        }

        $this->assertSame([], $wrong, implode("\n", $wrong));
    }

    public function test_manager_only_actions_are_refused_to_everyone_else(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');
        $session = $this->makeShift($owner);
        $station = $this->makeStation('Dapur');
        $variant = $this->makeMenuItem(categoryStation: $station);

        $tab = $this->apiOpenTab($session->id)->json('id');
        $line = max($this->apiAddLine($tab, $variant->id)->json('lines.*.id'));
        $this->postJson("/api/v1/tabs/{$tab}/send")->assertOk();
        \App\Models\KitchenTicketItem::query()->update(['status' => 'preparing', 'preparing_at' => now()]);

        foreach ($this->allRoles() as $name => $user) {
            if (in_array($name, ['owner', 'admin'], true)) {
                continue;
            }
            $this->actingAs($user, 'sanctum');

            // Batal tab, serah-terima, dan batal item yang SUDAH dimasak: hanya owner/admin.
            $this->postJson("/api/v1/tabs/{$tab}/void", ['reason' => 'x'])->assertForbidden("void tab sebagai $name");
            $this->postJson("/api/v1/tabs/{$tab}/handover", ['session_id' => $session->id])->assertForbidden("handover sebagai $name");
            // 048 / S3: tanpa order.void, batal item yang sudah dimasak butuh persetujuan (409 override_required);
            // peran tanpa menu floor ditolak lebih dulu (403). Apa pun jawabannya, baris tidak boleh hilang.
            $status = $this->deleteJson("/api/v1/tabs/{$tab}/items/{$line}", ['reason' => 'x', 'reason_code' => 'wrong_item', 'kitchen_status' => 'started', 'stock_decision' => 'waste'])->status();
            $this->assertContains($status, [403, 409], "batal item dimasak sebagai $name");
        }

        $this->assertSame('open', Order::find($tab)->status);
        $this->assertNotNull(\App\Models\OrderItem::find($line));
    }

    public function test_floor_actions_are_refused_to_roles_without_the_floor_key(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');
        $session = $this->makeShift($owner);
        $tab = $this->apiOpenTab($session->id)->json('id');
        $other = $this->apiOpenTab($session->id)->json('id');
        $line = max($this->apiAddLine($tab, $this->makeVariant()->id, 2)->json('lines.*.id'));
        $table = \App\Models\DiningTable::factory()->create();

        foreach (['kitchen-only', 'setup-only', 'inventory', 'none'] as $name) {
            $user = $this->allRoles()[$name];
            $this->actingAs($user, 'sanctum');

            $this->postJson("/api/v1/tabs/{$tab}/send")->assertForbidden("kirim sebagai $name");
            $this->postJson("/api/v1/tabs/{$tab}/move", ['table_id' => $table->id])->assertForbidden("pindah sebagai $name");
            $this->postJson("/api/v1/tabs/{$tab}/merge", ['source_tab_id' => $other])->assertForbidden("gabung sebagai $name");
            $this->postJson("/api/v1/tabs/{$tab}/split", ['client_ref' => $this->newLocalRef(), 'items' => [['item_id' => $line, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 1]]])->assertForbidden("pisah sebagai $name");
            $this->postJson("/api/v1/tabs/{$tab}/items", ['variant_id' => 1, 'qty' => 1])->assertForbidden("tambah item sebagai $name");
        }

        $this->assertSame(1, \App\Models\OrderItem::where('order_id', $tab)->count());
    }

    public function test_guests_are_unauthenticated(): void
    {
        $this->getJson('/api/v1/tabs')->assertUnauthorized();
        $this->postJson('/api/v1/tabs', [])->assertUnauthorized();
        $this->getJson('/api/v1/stations')->assertUnauthorized();
        $this->getJson('/api/v1/stations/1/tickets')->assertUnauthorized();
        $this->patchJson('/api/v1/ticket-items/1/status', ['status' => 'preparing'])->assertUnauthorized();
    }
}
