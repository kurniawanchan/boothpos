<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ReasonCode;
use App\Models\User;
use App\Services\OutletMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 045 Stage 3 (T050) — daftar Produk: chip ketersediaan per outlet dan filter outlet.
 */
class ProductsOutletsListTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private function product(int $outletId, string $name): Product
    {
        $p = Product::factory()->create(['home_outlet_id' => $outletId, 'category_id' => Category::factory()->create()->id, 'name' => $name]);
        $p->variants()->create(['sku' => strtoupper(substr(md5($name), 0, 8)), 'variant_name' => 'Standard', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 5]);

        return $p;
    }

    private function row(array $json, string $name): array
    {
        return collect($json['data'])->firstWhere('name', $name);
    }

    public function test_each_item_lists_its_state_at_every_visible_outlet(): void
    {
        [$a, $b, $c] = $this->makeOutlets(3)->all();
        $kopi = $this->product($a->id, 'Kopi');
        $teh = $this->product($a->id, 'Teh');
        app(OutletMenuService::class)->setCarried($teh, [$a->id, $c->id]);
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $reason = (int) ReasonCode::where('category', 'sold_out')->value('id');
        $this->postJson("/api/v1/products/{$kopi->id}/sold-out", ['reason_code_id' => $reason, 'outlet_ids' => [$b->id]])->assertCreated();

        $json = $this->getJson('/api/v1/products?tab=menu')->assertOk()->json();

        $states = fn ($name) => collect($this->row($json, $name)['availability'])->pluck('state', 'outlet_id')->all();
        $this->assertSame([$a->id => 'available', $b->id => 'sold_out', $c->id => 'available'], $states('Kopi'));
        $this->assertSame([$a->id => 'available', $b->id => 'not_carried', $c->id => 'available'], $states('Teh'));
        $this->assertSame('WJ2', collect($this->row($json, 'Kopi')['availability'])->firstWhere('outlet_id', $b->id)['outlet_code']);
    }

    public function test_the_outlet_filter_returns_only_items_carried_by_the_chosen_outlets(): void
    {
        [$a, $b] = $this->makeOutlets(2)->all();
        $this->product($a->id, 'Kopi');
        $teh = $this->product($a->id, 'Teh');
        app(OutletMenuService::class)->setCarried($teh, [$a->id]);
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $atB = collect($this->getJson("/api/v1/products?tab=menu&outlet_id[]={$b->id}")->assertOk()->json('data'))->pluck('name')->all();
        $atA = collect($this->getJson("/api/v1/products?tab=menu&outlet_id[]={$a->id}")->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Kopi'], $atB);
        $this->assertSame(['Kopi', 'Teh'], $atA);
    }

    public function test_the_selected_outlet_narrows_the_chips_to_that_outlet(): void
    {
        [$a, $b] = $this->makeOutlets(2)->all();
        $this->product($a->id, 'Kopi');
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $one = $this->withHeader('X-Outlet-Id', (string) $b->id)->getJson('/api/v1/products?tab=menu')->assertOk()->json();

        $this->assertSame([$b->id], collect($this->row($one, 'Kopi')['availability'])->pluck('outlet_id')->all(), 'only the selected outlet is shown');
    }

    public function test_a_single_outlet_install_keeps_the_single_entry_shape(): void
    {
        $a = $this->makeOutlets(1)[0];
        $this->product($a->id, 'Kopi');
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $json = $this->getJson('/api/v1/products?tab=menu')->assertOk()->json();

        $this->assertSame([null], collect($this->row($json, 'Kopi')['availability'])->pluck('outlet_id')->all());
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_items(): void
    {
        [$a] = $this->makeOutlets(3)->all();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/products?tab=menu&per_page=50')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->product($a->id, 'Kopi');
        $count(); // pemanasan: pekerjaan sekali-jalan (rilis/resep jatuh tempo, pengaturan) di-cache pada panggilan pertama
        $few = $count();
        for ($i = 0; $i < 8; $i++) {
            $this->product($a->id, "Item {$i}");
        }

        $this->assertSame($few, $count());
    }
}
