<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\OutletMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 069 (research D10, contracts/pos-categories.md) — GET /pos/categories mengikuti outlet shift kasir dengan aturan
 * "dijual di outlet ini" yang sama dengan GET /products?pos=1. Kategori yang punya item tetapi seluruhnya dijual di
 * outlet lain disembunyikan, supaya POS satu outlet tidak menampilkan tab kosong milik outlet lain. Kategori yang
 * sama sekali belum punya item tetap tampil dengan hitungan 0 (055), karena tab POS berasal dari server, bukan dari
 * produk.
 */
class PosCategoriesOutletTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private $a;

    private $b;

    /** @var array<string, Category> */
    private array $cat = [];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->a, $this->b] = $this->makeOutlets(2)->all();
        foreach (['Bersama', 'HanyaA', 'HanyaB', 'Kosong', 'Nonaktif'] as $i => $name) {
            $this->cat[$name] = Category::factory()->create(['name' => $name, 'display_order' => $i]);
        }
        $menu = app(OutletMenuService::class);
        $item = function (string $category, string $name, array $carriedAt, bool $active = true) use ($menu) {
            $product = Product::factory()->create(['home_outlet_id' => $this->a->id, 'category_id' => $this->cat[$category]->id, 'name' => $name, 'is_active' => $active]);
            $menu->setCarried($product, array_map(fn ($o) => $o->id, $carriedAt));

            return $product;
        };
        $item('Bersama', 'Kopi A', [$this->a]);
        $item('Bersama', 'Kopi B', [$this->b]);
        $item('Bersama', 'Kopi AB', [$this->a, $this->b]);
        $item('HanyaA', 'Nasi A', [$this->a]);
        $item('HanyaB', 'Mie B', [$this->b]);
        $item('Nonaktif', 'Lama B', [$this->b], active: false);
    }

    private function cashierAt($outlet): void
    {
        $cashier = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$this->a, $this->b]);
        $this->shiftAt($outlet, $cashier);
        $this->actingAs($cashier, 'sanctum');
    }

    /** @return array<string, int> nama kategori → product_count */
    private function tabs(): array
    {
        return collect($this->getJson('/api/v1/pos/categories')->assertOk()->json('data'))->pluck('product_count', 'name')->all();
    }

    public function test_a_cashier_with_a_shift_sees_only_categories_that_are_sold_at_that_outlet_or_have_no_items_at_all(): void
    {
        $this->cashierAt($this->b);

        $tabs = $this->tabs();

        $this->assertSame(['Bersama', 'HanyaB', 'Kosong', 'Nonaktif'], array_keys($tabs));
        $this->assertArrayNotHasKey('HanyaA', $tabs, 'kategori yang seluruh itemnya dijual di outlet lain disembunyikan');
    }

    public function test_the_other_outlet_sees_the_mirror_image(): void
    {
        $this->cashierAt($this->a);

        $this->assertSame(['Bersama', 'HanyaA', 'Kosong'], array_keys($this->tabs()));
    }

    public function test_product_count_counts_only_active_items_sold_at_the_shift_outlet(): void
    {
        $this->cashierAt($this->b);
        $this->assertSame(2, $this->tabs()['Bersama'], 'Kopi B dan Kopi AB; Kopi A tidak dijual di B');

        $this->cashierAt($this->a);
        $this->assertSame(2, $this->tabs()['Bersama'], 'Kopi A dan Kopi AB');
    }

    public function test_a_category_with_no_items_at_all_stays_with_count_zero_and_so_does_one_whose_items_here_are_inactive(): void
    {
        $this->cashierAt($this->b);

        $tabs = $this->tabs();

        $this->assertSame(0, $tabs['Kosong']);
        $this->assertSame(0, $tabs['Nonaktif'], 'item dijual di sini tetapi nonaktif: tab tetap, hitungan 0 (perilaku 055)');
    }

    public function test_without_a_shift_and_with_several_outlets_the_list_is_unfiltered(): void
    {
        $user = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$this->a, $this->b]);
        $this->actingAs($user, 'sanctum');

        $this->assertSame(['Bersama', 'HanyaA', 'HanyaB', 'Kosong', 'Nonaktif'], array_keys($this->tabs()));
    }
}
