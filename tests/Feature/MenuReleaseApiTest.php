<?php

namespace Tests\Feature;

use App\Models\MenuChange;
use App\Models\MenuRelease;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\PriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 043-products-page-redesign (T064) — /menu/changes dan /menu/releases.
 */
class MenuReleaseApiTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
    }

    private function on(): void
    {
        Setting::updateOrCreate(['key' => 'menu_release_enabled'], ['value' => 'true', 'type' => 'boolean']);
    }

    private function change(float $to = 10500): array
    {
        $v = $this->makeVariant(stock: 1, price: 10000);
        app(PriceService::class)->setBasePrice($v, $to, $this->owner);

        return [$v, MenuChange::orderByDesc('id')->first()];
    }

    public function test_writes_are_refused_while_releases_are_disabled_but_reads_work(): void
    {
        $this->getJson('/api/v1/menu/releases')->assertOk()->assertJsonPath('release_enabled', false);
        $this->getJson('/api/v1/menu/changes')->assertOk();

        $this->postJson('/api/v1/menu/releases', [])->assertStatus(409);
    }

    public function test_pending_changes_are_listed_with_a_summary_and_can_be_filtered(): void
    {
        $this->on();
        [$v, $small] = $this->change(10500);
        [, $big] = $this->change(30000);

        $rows = collect($this->getJson('/api/v1/menu/changes')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(['pending', '10500.00', '10000.00'], [$rows[$small->id]['status'], $rows[$small->id]['new_price'], $rows[$small->id]['old_price']]);
        $this->assertSame('awaiting_approval', $rows[$big->id]['status']);
        $this->assertSame($this->owner->name, $rows[$big->id]['requested_by']);
        $this->assertSame([$big->id], array_column($this->getJson('/api/v1/menu/changes?status=awaiting_approval')->json('data'), 'id'));
    }

    public function test_publishing_now_creates_version_one_applies_the_changes_and_is_listed_with_its_contents(): void
    {
        $this->on();
        [$v, $c] = $this->change(10500);

        $res = $this->postJson('/api/v1/menu/releases', ['note' => 'rilis pertama'])->assertCreated();

        $res->assertJsonPath('version', 1)->assertJsonPath('status', 'published')->assertJsonPath('change_count', 1);
        $this->assertSame('10500.00', (string) $v->fresh()->sell_price);

        $id = $res->json('id');
        $detail = $this->getJson("/api/v1/menu/releases/{$id}")->assertOk()->json();
        $this->assertSame([$c->id], array_column($detail['changes'], 'id'));
        $this->assertSame($this->owner->name, $detail['released_by']);
        $this->assertSame([1], array_column($this->getJson('/api/v1/menu/releases')->json('data'), 'version'));
    }

    public function test_a_scheduled_release_waits_and_can_be_cancelled(): void
    {
        $this->on();
        [$v] = $this->change(10500);

        $id = $this->postJson('/api/v1/menu/releases', ['publish_at' => now()->addDay()->toIso8601String()])->assertCreated()->assertJsonPath('status', 'scheduled')->json('id');
        $this->assertSame('10000.00', (string) $v->fresh()->sell_price);

        $this->postJson("/api/v1/menu/releases/{$id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson("/api/v1/menu/releases/{$id}/cancel")->assertStatus(409);
    }

    public function test_choosing_a_change_that_still_awaits_approval_is_a_conflict(): void
    {
        $this->on();
        [, $ok] = $this->change(10500);
        [, $big] = $this->change(30000);

        $this->postJson('/api/v1/menu/releases', ['change_ids' => [$ok->id, $big->id]])->assertStatus(409);
        $this->assertSame(0, MenuRelease::count());
        $this->postJson('/api/v1/menu/releases', ['change_ids' => [$ok->id]])->assertCreated();
        $this->assertSame('awaiting_approval', $big->fresh()->status);
    }

    public function test_nothing_to_release_is_a_422(): void
    {
        $this->on();

        $this->postJson('/api/v1/menu/releases', [])->assertStatus(422);
    }

    public function test_publishing_needs_the_permission_and_cancelling_a_change_needs_the_requester_or_the_permission(): void
    {
        $this->on();
        [$v, $c] = $this->change(10500);

        $role = Role::factory()->create(['menu_keys' => ['products'], 'permissions' => []]);
        $stranger = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($stranger, 'sanctum');
        $this->postJson('/api/v1/menu/releases', [])->assertForbidden();
        $this->postJson("/api/v1/menu/changes/{$c->id}/cancel")->assertForbidden();

        $this->actingAs($this->owner, 'sanctum');
        $this->postJson("/api/v1/menu/changes/{$c->id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson("/api/v1/menu/changes/{$c->id}/cancel")->assertStatus(409);
    }

    public function test_cancelling_an_awaiting_change_also_closes_its_approval(): void
    {
        $this->on();
        [, $big] = $this->change(30000);

        $this->postJson("/api/v1/menu/changes/{$big->id}/cancel")->assertOk();

        $this->assertSame('rejected', \App\Models\Approval::sole()->decision);
    }
}
