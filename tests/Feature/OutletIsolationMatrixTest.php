<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Event;
use App\Models\Product;
use App\Models\ReasonCode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 045 Stage 2 (T033) — kebocoran antar-outlet: setiap jalur baca harus mengikuti outlet pengguna.
 *
 * Dua outlet (A, B), dua kasir (masing-masing satu outlet), dan Owner. Order di A bernilai 20.000,
 * di B 30.000. Kasir A tidak boleh melihat apa pun milik B — lewat daftar, detail langsung, angka
 * laporan, maupun cache dashboard. Owner melihat semuanya, dan header X-Outlet-Id mempersempit.
 */
class OutletIsolationMatrixTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private $a;

    private $b;

    private User $cashierA;

    private User $cashierB;

    private $orderA;

    private $orderB;

    private $shiftA;

    private $shiftB;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->a, $this->b] = $this->makeOutlets(2)->all();
        $this->cashierA = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$this->a]);
        $this->cashierB = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$this->b]);

        $this->event = Event::factory()->create(['status' => 'active']);
        $product = Product::factory()->create(['home_outlet_id' => $this->a->id, 'category_id' => Category::factory()->create()->id]);
        $variant = $product->variants()->create(['sku' => 'ISO'.fake()->unique()->numerify('######'), 'variant_name' => 'Standard', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        $this->shiftA = $this->shiftAt($this->a, $this->cashierA, ['event_id' => $this->event->id]);
        $this->shiftB = $this->shiftAt($this->b, $this->cashierB, ['event_id' => $this->event->id]);
        $this->orderA = $this->orderAt($this->shiftA, $variant, 2); // 20.000
        $this->orderB = $this->orderAt($this->shiftB, $variant, 3); // 30.000
    }

    public function test_the_orders_list_only_contains_the_outlets_of_the_user(): void
    {
        $this->actingAs($this->cashierA, 'sanctum');

        $ids = collect($this->getJson('/api/v1/orders')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertContains($this->orderA->id, $ids);
        $this->assertNotContains($this->orderB->id, $ids);
    }

    public function test_an_order_of_another_outlet_is_not_found_by_id(): void
    {
        $this->actingAs($this->cashierA, 'sanctum');

        $this->getJson("/api/v1/orders/{$this->orderA->id}")->assertOk();
        $this->getJson("/api/v1/orders/{$this->orderB->id}")->assertNotFound();
    }

    public function test_a_shift_of_another_outlet_is_not_found_by_id(): void
    {
        $this->actingAs($this->cashierA, 'sanctum');

        $this->getJson("/api/v1/sessions/{$this->shiftB->id}/summary")->assertNotFound();
    }

    public function test_the_sales_report_numbers_follow_the_outlet_of_the_user(): void
    {
        $this->actingAs($this->cashierA, 'sanctum');
        $mine = $this->getJson("/api/v1/reports/sales?event_id={$this->event->id}")->assertOk();
        $this->assertCount(1, $mine->json('transactions'));

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $all = $this->getJson("/api/v1/reports/sales?event_id={$this->event->id}")->assertOk();
        $this->assertCount(2, $all->json('transactions'));

        $narrowed = $this->withHeader('X-Outlet-Id', (string) $this->b->id)
            ->getJson("/api/v1/reports/sales?event_id={$this->event->id}")->assertOk();
        $this->assertSame([$this->orderB->id], collect($narrowed->json('transactions'))->pluck('id')->all());
    }

    public function test_the_dashboard_cache_does_not_leak_between_outlets(): void
    {
        // Owner dahulu: bila kunci cache tidak memuat outlet, kasir A akan menerima angka Owner.
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $owner = $this->getJson('/api/v1/dashboard/summary?period=today')->assertOk()->json();

        $this->actingAs($this->cashierA, 'sanctum');
        $cashier = $this->getJson('/api/v1/dashboard/summary?period=today')->assertOk()->json();

        $this->assertNotEquals($owner['kpis'] ?? $owner, $cashier['kpis'] ?? $cashier, 'cashier A must not receive the owner figures');
    }

    public function test_tables_follow_the_selected_outlet(): void
    {
        $this->tableAt($this->a, 'A1');
        $this->tableAt($this->b, 'B1');
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $all = collect($this->getJson('/api/v1/dining-tables')->assertOk()->json('data'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['A1', 'B1'], $all);

        $only = collect($this->withHeader('X-Outlet-Id', (string) $this->a->id)->getJson('/api/v1/dining-tables')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertSame(['A1'], $only);
    }

    // ---- Stage 3: endpoint produk / menu per outlet --------------------------------------------------

    /** Pengelola menu tanpa all_outlets yang hanya ditugaskan ke outlet A. */
    private function menuManagerAt($outlet): User
    {
        $role = Role::create([
            'name' => 'Pengelola Menu '.uniqid(), 'menu_keys' => ['dashboard', 'products'], 'all_outlets' => false,
            'permissions' => [\App\Support\PermissionCatalog::ITEM_86, \App\Support\PermissionCatalog::PRICE_BULK, \App\Support\PermissionCatalog::ITEM_MANAGE],
        ]);

        return $this->assignUser(User::factory()->create(['role_id' => $role->id]), [$outlet]);
    }

    private function menuItem(): Product
    {
        $p = Product::factory()->create(['home_outlet_id' => $this->a->id, 'category_id' => Category::factory()->create()->id, 'name' => 'Menu Uji']);
        $p->variants()->create(['sku' => 'MTX00001', 'variant_name' => 'Regular', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 5]);

        return $p;
    }

    public function test_product_list_and_detail_never_mention_an_outlet_the_user_cannot_see(): void
    {
        $p = $this->menuItem();
        $this->actingAs($this->menuManagerAt($this->a), 'sanctum');

        $list = $this->getJson('/api/v1/products?tab=menu')->assertOk()->json('data');
        $entry = collect($list)->firstWhere('id', $p->id);
        $this->assertSame([null], collect($entry['availability'])->pluck('outlet_id')->all(), 'one visible outlet keeps the legacy shape');

        $detail = $this->getJson("/api/v1/products/{$p->id}")->assertOk()->json();
        $this->assertSame([null], collect($detail['availability'])->pluck('outlet_id')->all());
        $this->assertNull($detail['outlet_prices']);
    }

    public function test_marking_86_or_pricing_at_an_outlet_outside_the_users_set_is_forbidden(): void
    {
        $p = $this->menuItem();
        $variant = $p->variants()->first();
        $this->actingAs($this->menuManagerAt($this->a), 'sanctum');
        $reason = (int) ReasonCode::where('category', 'sold_out')->value('id');

        $this->postJson("/api/v1/products/{$p->id}/sold-out", ['reason_code_id' => $reason, 'outlet_ids' => [$this->b->id]])->assertStatus(422);
        $this->deleteJson("/api/v1/products/{$p->id}/sold-out", ['outlet_ids' => [$this->b->id]])->assertForbidden();
        $this->putJson("/api/v1/variants/{$variant->id}/outlet-prices/{$this->b->id}", ['price' => 12000])->assertForbidden();
        $this->withHeader('X-Outlet-Id', (string) $this->b->id)->getJson('/api/v1/menu/availability')->assertForbidden();
    }

    public function test_the_outlet_price_listing_only_shows_permitted_outlets(): void
    {
        $variant = $this->menuItem()->variants()->first();
        $this->actingAs($this->menuManagerAt($this->a), 'sanctum');

        $ids = collect($this->getJson("/api/v1/variants/{$variant->id}/outlet-prices")->assertOk()->json('outlets'))->pluck('id')->all();

        $this->assertSame([$this->a->id], $ids);
    }

    public function test_a_global_86_is_visible_to_a_restricted_user_but_an_outlet_86_elsewhere_is_not(): void
    {
        $p = $this->menuItem();
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');
        $reason = (int) ReasonCode::where('category', 'sold_out')->value('id');
        $this->postJson("/api/v1/products/{$p->id}/sold-out", ['reason_code_id' => $reason, 'outlet_ids' => [$this->b->id]])->assertCreated();

        $this->actingAs($this->menuManagerAt($this->a), 'sanctum');
        $marks = $this->getJson('/api/v1/menu/availability')->assertOk()->json();

        $this->assertStringNotContainsString('"outlet_id":'.$this->b->id, json_encode($marks));
    }
}
