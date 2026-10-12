<?php

namespace Tests\Feature;

use App\Models\ItemAvailabilityMark;
use App\Models\MenuChange;
use App\Models\ReasonCode;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 043-products-page-redesign (T049) — diskontinu, aktifkan lagi, dan penerbitan item baru.
 */
class ProductLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    private function reasonId(): int
    {
        return (int) ReasonCode::where('category', 'discontinue')->orderBy('sort')->value('id');
    }

    private function product()
    {
        return $this->makeVariant(stock: 10, price: 10000)->product;
    }

    public function test_discontinue_needs_a_reason_and_the_permission(): void
    {
        $p = $this->product();
        $this->postJson("/api/v1/products/{$p->id}/discontinue", [])->assertStatus(422);
        $this->postJson("/api/v1/products/{$p->id}/discontinue", ['reason_code_id' => ReasonCode::where('category', 'sold_out')->value('id')])->assertStatus(422);

        $role = Role::factory()->create(['menu_keys' => ['products']]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]), 'sanctum');
        $this->postJson("/api/v1/products/{$p->id}/discontinue", ['reason_code_id' => $this->reasonId()])->assertForbidden();
        $this->assertSame('active', $p->fresh()->status);
    }

    public function test_immediate_mode_discontinues_clears_the_86_mark_and_logs(): void
    {
        $p = $this->product();
        ItemAvailabilityMark::create(['product_id' => $p->id, 'reason_code_id' => ReasonCode::where('category', 'sold_out')->value('id'), 'marked_at' => now(), 'expires_at' => now()->addHour()]);

        $this->postJson("/api/v1/products/{$p->id}/discontinue", ['reason_code_id' => $this->reasonId(), 'note' => 'musim berakhir'])
            ->assertOk()->assertJsonPath('status', 'discontinued')->assertJsonPath('is_active', false)->assertJsonPath('change', null);

        $this->assertNotNull($p->fresh()->discontinued_at);
        $this->assertSame(0, ItemAvailabilityMark::query()->active()->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'item_discontinued', 'entity_id' => $p->id]);
        $this->postJson("/api/v1/products/{$p->id}/discontinue", ['reason_code_id' => $this->reasonId()])->assertStatus(409);
    }

    public function test_a_discontinued_item_cannot_be_ordered_but_history_is_untouched_and_it_can_be_reactivated(): void
    {
        $session = $this->makeShift(auth()->user() ?? User::factory()->create(['role' => 'owner']));
        $variant = $this->makeVariant(stock: 10, price: 10000);
        $this->postJson('/api/v1/orders', [
            'session_id' => $session->id, 'local_ref' => (string) \Illuminate\Support\Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 10000]],
        ])->assertCreated();

        $this->postJson("/api/v1/products/{$variant->product_id}/discontinue", ['reason_code_id' => $this->reasonId()])->assertOk();
        $this->postJson('/api/v1/orders', [
            'session_id' => $session->id, 'local_ref' => (string) \Illuminate\Support\Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 10000]],
        ])->assertStatus(409);
        $this->assertDatabaseCount('order_items', 1);                                               // riwayat utuh

        $this->postJson("/api/v1/products/{$variant->product_id}/reactivate")->assertOk()->assertJsonPath('status', 'active');
        $this->assertNull($variant->product->fresh()->discontinued_at);
        $this->postJson("/api/v1/products/{$variant->product_id}/reactivate")->assertStatus(409);
    }

    public function test_release_mode_queues_a_change_and_leaves_the_item_active_until_released(): void
    {
        Setting::updateOrCreate(['key' => 'menu_release_enabled'], ['value' => 'true', 'type' => 'boolean']);
        $p = $this->product();

        $res = $this->postJson("/api/v1/products/{$p->id}/discontinue", ['reason_code_id' => $this->reasonId()])->assertOk();

        $res->assertJsonPath('status', 'active')->assertJsonPath('change.kind', 'item_discontinue')->assertJsonPath('change.status', 'pending');
        $this->assertSame('active', $p->fresh()->status);
        $this->assertSame(1, MenuChange::count());
        $this->assertSame($p->id, MenuChange::first()->subject_id);
    }

    public function test_a_new_product_is_a_draft_with_a_publish_change_when_releases_are_on_and_active_otherwise(): void
    {
        $base = $this->product();
        $payload = fn (string $name) => [
            'home_outlet_id' => $base->home_outlet_id, 'category_id' => $base->category_id, 'name' => $name,
            'variants' => [['variant_name' => 'Reguler', 'sell_price' => 10000]],
        ];

        $id = $this->postJson('/api/v1/products', $payload('Langsung Aktif'))->assertCreated()->json('id');
        $this->assertSame('active', \App\Models\Product::find($id)->status);

        Setting::updateOrCreate(['key' => 'menu_release_enabled'], ['value' => 'true', 'type' => 'boolean']);
        $id = $this->postJson('/api/v1/products', $payload('Bubur Kacang Hijau'))->assertCreated()->json('id');
        $this->assertSame('draft', \App\Models\Product::find($id)->status);
        $this->assertDatabaseHas('menu_changes', ['kind' => 'item_publish', 'subject_id' => $id, 'status' => 'pending']);
    }
}
