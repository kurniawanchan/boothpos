<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 055 / T005 — GET /pos/categories: menurut mode sistem, kategori nonaktif disembunyikan, kategori tanpa produk tetap
 * dikembalikan dengan hitungan 0, urutan display_order lalu nama, dan akses tanpa sesi ditolak.
 */
class PosCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private function categoryFor(string $code, string $name, string $mode, int $order = 0, bool $active = true): Category
    {
        return ModeGate::runAs($mode, fn () => Category::create([
            'code' => $code, 'name' => $name, 'display_order' => $order, 'is_active' => $active,
        ]));
    }

    private function product(Category $category, string $mode, bool $active = true): void
    {
        ModeGate::runAs($mode, fn () => Product::factory()->create([
            'category_id' => $category->id, 'is_active' => $active,
            'home_outlet_id' => Outlet::first()?->id ?? Outlet::factory()->create()->id,
        ]));
    }

    /** Permintaan dijalankan dalam mode yang sama dengan data uji; mode default server adalah LIVE. */
    private function categories(string $mode = 'demo'): \Illuminate\Testing\TestResponse
    {
        return ModeGate::runAs($mode, fn () => $this->getJson('/api/v1/pos/categories'));
    }

    private function asCashier(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');
    }

    public function test_returns_only_the_categories_of_the_active_mode(): void
    {
        $demo = $this->categoryFor('KP', 'Kopi', 'demo');
        $this->categoryFor('PN', 'Pin Emamel', 'live');

        $this->asCashier();
        ModeGate::runAs('demo', fn () => $this->getJson('/api/v1/pos/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $demo->id));

        ModeGate::runAs('live', fn () => $this->getJson('/api/v1/pos/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Pin Emamel'));
    }

    public function test_an_inactive_category_is_hidden(): void
    {
        $this->categoryFor('KP', 'Kopi', 'demo');
        $this->categoryFor('XX', 'Lama', 'demo', 0, false);

        $this->asCashier();
        $this->categories()->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'KP');
    }

    public function test_a_category_without_sellable_products_is_kept_with_count_zero(): void
    {
        $empty = $this->categoryFor('KY', 'Gantungan Kunci', 'demo');
        $stocked = $this->categoryFor('KP', 'Kopi', 'demo');
        $this->product($stocked, 'demo');
        $this->product($stocked, 'demo', active: false); // tidak dihitung: produk nonaktif

        $this->asCashier();
        $rows = $this->categories()->assertOk()->json('data');

        $byCode = collect($rows)->keyBy('code');
        $this->assertSame(0, $byCode['KY']['product_count']);
        $this->assertSame(1, $byCode['KP']['product_count']);
        $this->assertSame($empty->id, $byCode['KY']['id']);
    }

    public function test_orders_by_display_order_then_name(): void
    {
        $this->categoryFor('B', 'Bravo', 'demo', 2);
        $this->categoryFor('A', 'Alfa', 'demo', 2);
        $this->categoryFor('C', 'Charlie', 'demo', 1);

        $this->asCashier();
        $names = collect($this->categories()->assertOk()->json('data'))->pluck('name')->all();
        $this->assertSame(['Charlie', 'Alfa', 'Bravo'], $names);
    }

    public function test_any_signed_in_role_can_read_it(): void
    {
        $this->categoryFor('KP', 'Kopi', 'demo');
        $this->actingAs(User::factory()->create(['role' => 'inventory']), 'sanctum');
        $this->getJson('/api/v1/pos/categories')->assertOk();
    }

    public function test_no_session_is_refused(): void
    {
        $this->getJson('/api/v1/pos/categories')->assertUnauthorized();
    }
}
