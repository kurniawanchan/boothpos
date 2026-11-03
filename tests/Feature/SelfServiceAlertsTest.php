<?php

namespace Tests\Feature;

use App\Models\SelfServiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\Support\SelfServiceFixtures;
use Tests\TestCase;

/** 090 / US8 — endpoint polling untuk tablet: pesanan baru sejak kursor, jumlah menunggu, tanpa putar ulang. */
class SelfServiceAlertsTest extends TestCase
{
    use OutletFixtures;
    use RefreshDatabase;
    use SelfServiceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ssSetUp();
    }

    private function place(?string $token = null): SelfServiceOrder
    {
        return $this->ssPlaceOrder([['variant_id' => $this->makeMenuItem()->id, 'qty' => 1]], [], $token);
    }

    public function test_without_a_cursor_it_only_initialises_so_nothing_old_is_replayed(): void
    {
        $this->place();
        $last = $this->place();
        $this->actingAs($this->ssCashier(), 'sanctum');

        $this->getJson('/api/v1/self-service/alerts')->assertOk()
            ->assertJsonPath('items', [])->assertJsonPath('waiting_count', 2)->assertJsonPath('last_id', $last->id);
    }

    public function test_it_returns_only_new_orders_in_order_and_advances_the_cursor(): void
    {
        $first = $this->place();
        $this->actingAs($this->ssCashier(), 'sanctum');
        $cursor = $this->getJson('/api/v1/self-service/alerts')->json('last_id');
        $this->assertSame($first->id, $cursor);

        $a = $this->place();
        $b = $this->place();
        $res = $this->getJson('/api/v1/self-service/alerts?after_id='.$cursor)->assertOk();

        $this->assertSame([$a->id, $b->id], collect($res->json('items'))->pluck('id')->all());
        $this->assertSame('Meja 5', $res->json('items.0.table_name'));
        $this->assertSame($a->reference_no, $res->json('items.0.reference_no'));
        $res->assertJsonPath('last_id', $b->id)->assertJsonPath('waiting_count', 3);
        $this->getJson('/api/v1/self-service/alerts?after_id='.$b->id)->assertJsonPath('items', [])->assertJsonPath('last_id', $b->id);
    }

    public function test_orders_already_handled_by_someone_else_are_not_announced_but_the_cursor_passes_them(): void
    {
        $this->actingAs($this->ssCashier(), 'sanctum');
        $handled = $this->place();
        $waiting = $this->place();
        $handled->forceFill(['status' => SelfServiceOrder::REJECTED])->save();

        $res = $this->getJson('/api/v1/self-service/alerts?after_id=0')->assertOk();

        $this->assertSame([$waiting->id], collect($res->json('items'))->pluck('id')->all());
        $res->assertJsonPath('last_id', $waiting->id)->assertJsonPath('waiting_count', 1);
    }

    public function test_a_large_backlog_is_delivered_in_batches_without_skipping(): void
    {
        $this->actingAs($this->ssCashier(), 'sanctum');
        $ids = collect(range(1, 25))->map(fn () => $this->place()->id)->all();

        $one = $this->getJson('/api/v1/self-service/alerts?after_id=0')->assertOk();
        $this->assertCount(20, $one->json('items'));
        $this->assertSame($ids[19], $one->json('last_id'));

        $two = $this->getJson('/api/v1/self-service/alerts?after_id='.$one->json('last_id'))->assertOk();
        $this->assertSame(array_slice($ids, 20), collect($two->json('items'))->pluck('id')->all());
    }

    public function test_it_carries_no_customer_data_and_no_proof(): void
    {
        $this->place();
        $this->actingAs($this->ssCashier(), 'sanctum');

        $raw = $this->getJson('/api/v1/self-service/alerts?after_id=0')->assertOk()->getContent();

        foreach (['customer', 'phone', 'email', 'proof', 'receipt_token', 'lines'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw);
        }
    }

    public function test_it_is_limited_to_the_outlets_the_user_can_see(): void
    {
        [$mine, $other] = $this->makeOutlets(2);
        $own = $this->place(\App\Support\SelfServiceQrToken::issue($this->tableAt($mine, 'A1')));
        $this->place(\App\Support\SelfServiceQrToken::issue($this->tableAt($other, 'B1')));
        $this->actingAs($this->assignUser($this->ssCashier(), [$mine]), 'sanctum');

        $res = $this->getJson('/api/v1/self-service/alerts?after_id=0')->assertOk();

        $this->assertSame([$own->id], collect($res->json('items'))->pluck('id')->all());
        $res->assertJsonPath('waiting_count', 1);
    }

    public function test_menu_pos_or_self_service_may_poll_but_others_may_not_and_guests_get_401(): void
    {
        // Peran tanpa menu `pos` maupun `self_service` (Inventory bawaan punya `pos`, jadi dibuat peran sempit).
        $narrow = \App\Models\Role::factory()->create(['menu_keys' => ['dashboard']]);
        $this->actingAs(User::factory()->create(['role_id' => $narrow->id]), 'sanctum');
        $this->getJson('/api/v1/self-service/alerts')->assertForbidden();

        $this->actingAs($this->ssCashier(), 'sanctum');
        $this->getJson('/api/v1/self-service/alerts')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/self-service/alerts')->assertUnauthorized();
    }

    public function test_the_cursor_must_be_a_non_negative_integer(): void
    {
        $this->actingAs($this->ssCashier(), 'sanctum');

        $this->getJson('/api/v1/self-service/alerts?after_id=-1')->assertStatus(422);
        $this->getJson('/api/v1/self-service/alerts?after_id=abc')->assertStatus(422);
    }
}
