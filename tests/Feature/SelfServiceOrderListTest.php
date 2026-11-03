<?php

namespace Tests\Feature;

use App\Models\SelfServiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\Support\SelfServiceFixtures;
use Tests\TestCase;

/**
 * 090 / US6 — tab Self-Service untuk staf: daftar, filter, hitungan lencana, dan gerbang akses.
 */
class SelfServiceOrderListTest extends TestCase
{
    use OutletFixtures;
    use RefreshDatabase;
    use SelfServiceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ssSetUp();
    }

    private function place(int $qty = 1, ?string $token = null): SelfServiceOrder
    {
        $item = $this->makeMenuItem(price: 10000);

        return $this->ssPlaceOrder([['variant_id' => $item->id, 'qty' => $qty]], [], $token);
    }

    public function test_the_list_shows_pending_orders_first_oldest_first_with_badge_counts(): void
    {
        $first = $this->place();
        $second = $this->place();
        $done = $this->place();
        $done->forceFill(['status' => SelfServiceOrder::DISPATCHED])->save();
        $rejected = $this->place();
        $rejected->forceFill(['status' => SelfServiceOrder::REJECTED])->save();
        $this->actingAs($this->ssOwner(), 'sanctum');

        $res = $this->getJson('/api/v1/self-service/orders')->assertOk();

        $this->assertSame([$first->id, $second->id, $rejected->id, $done->id], collect($res->json('data'))->pluck('id')->all());
        $res->assertJsonPath('meta.counts.awaiting_verification', 2)
            ->assertJsonPath('meta.counts.verified', 0)
            ->assertJsonPath('meta.counts.dispatched', 1)
            ->assertJsonPath('meta.counts.rejected', 1)
            ->assertJsonPath('meta.counts.pending', 2)
            ->assertJsonPath('meta.total', 4);
    }

    public function test_a_row_carries_what_the_tab_needs_and_the_detail_adds_the_lines(): void
    {
        $item = $this->makeMenuItem(price: 20000);
        $placed = $this->ssPlaceOrder([['variant_id' => $item->id, 'qty' => 2, 'note' => 'tanpa gula']]);
        $this->actingAs($this->ssOwner(), 'sanctum');

        $row = $this->getJson('/api/v1/self-service/orders')->assertOk()->json('data.0');
        $this->assertSame('Meja 5', $row['table']['name']);
        $this->assertSame('dine_in', $row['service_type']);
        $this->assertSame(2, $row['item_count']);
        $this->assertSame('40000.00', $row['total_amount']);
        $this->assertSame('waiting_verification', $row['payment']['status']);
        $this->assertSame($placed->proof_id, $row['payment']['proof_id']);
        $this->assertArrayNotHasKey('lines', $row, 'daftar tetap ramping');

        $detail = $this->getJson("/api/v1/self-service/orders/{$placed->id}")->assertOk()->json('data');
        $this->assertSame('tanpa gula', $detail['lines'][0]['note']);
        $this->assertSame(2, $detail['lines'][0]['qty']);
        $this->assertSame('QRIS', $detail['payment']['channel']);
    }

    public function test_filters_by_status_table_and_search_and_paginates(): void
    {
        $a = $this->place();
        $b = $this->place();
        $b->forceFill(['status' => SelfServiceOrder::VERIFIED])->save();
        $otherTable = \App\Models\DiningTable::factory()->create(['name' => 'Meja 9']);
        $c = $this->place(1, \App\Support\SelfServiceQrToken::issue($otherTable));
        $this->actingAs($this->ssOwner(), 'sanctum');
        $ids = fn ($res) => collect($res->json('data'))->pluck('id')->all();

        $this->assertSame([$b->id], $ids($this->getJson('/api/v1/self-service/orders?status[]=verified')));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids($this->getJson('/api/v1/self-service/orders?status[]=awaiting_verification&status[]=verified&table_id='.$this->ssTable->id)));
        $this->assertSame([$c->id], $ids($this->getJson('/api/v1/self-service/orders?q=Meja 9')));
        $this->assertSame([$a->id], $ids($this->getJson('/api/v1/self-service/orders?q='.$a->reference_no)));

        $page = $this->getJson('/api/v1/self-service/orders?per_page=2')->assertOk();
        $this->assertCount(2, $page->json('data'));
        $page->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.total', 3);
        // Hitungan lencana tidak ikut filter.
        $this->getJson('/api/v1/self-service/orders?status[]=verified')->assertJsonPath('meta.counts.pending', 3);

        $this->getJson('/api/v1/self-service/orders?status[]=bukan')->assertStatus(422);
    }

    public function test_only_roles_with_the_self_service_menu_can_see_it(): void
    {
        $order = $this->place();

        $this->actingAs(User::factory()->create(['role' => 'inventory']), 'sanctum');
        $this->getJson('/api/v1/self-service/orders')->assertForbidden();
        $this->getJson("/api/v1/self-service/orders/{$order->id}")->assertForbidden();

        $this->actingAs($this->ssCashier(), 'sanctum');
        $this->getJson('/api/v1/self-service/orders')->assertOk();
    }

    public function test_guests_get_401(): void
    {
        $order = $this->place();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/self-service/orders')->assertUnauthorized();
        $this->getJson("/api/v1/self-service/orders/{$order->id}")->assertUnauthorized();
        $this->postJson("/api/v1/self-service/orders/{$order->id}/dispatch")->assertUnauthorized();
    }

    public function test_orders_of_outlets_the_user_cannot_see_are_not_listed_and_are_not_found(): void
    {
        [$mine, $other] = $this->makeOutlets(2);
        $mineTable = $this->tableAt($mine, 'A1');
        $otherTable = $this->tableAt($other, 'B1');
        $ownOrder = $this->place(1, \App\Support\SelfServiceQrToken::issue($mineTable));
        $foreign = $this->place(1, \App\Support\SelfServiceQrToken::issue($otherTable));
        $user = $this->assignUser($this->ssCashier(), [$mine]);
        $this->actingAs($user, 'sanctum');

        $res = $this->getJson('/api/v1/self-service/orders')->assertOk();

        $this->assertSame([$ownOrder->id], collect($res->json('data'))->pluck('id')->all());
        $res->assertJsonPath('meta.counts.pending', 1);
        $this->getJson("/api/v1/self-service/orders/{$foreign->id}")->assertNotFound();
        $this->postJson("/api/v1/self-service/orders/{$foreign->id}/reject", ['reason' => 'x'])->assertNotFound();
    }
}
