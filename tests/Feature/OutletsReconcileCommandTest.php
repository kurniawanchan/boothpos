<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/** 045 (T008) — outlets:reconcile: tidak ada selisih bila data tidak berubah, dan GAGAL (kode ≠ 0) bila ada. */
class OutletsReconcileCommandTest extends TestCase
{
    use OutletFixtures;
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/reconcile-'.uniqid();
        $outlets = $this->makeOutlets(2);
        foreach ($outlets as $outlet) {
            $product = Product::factory()->create(['home_outlet_id' => $outlet->id, 'category_id' => Category::factory()->create()->id]);
            $product->variants()->create(['sku' => $outlet->code.'KYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 50]);
        }
        User::factory()->create(['role' => 'owner']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function reconcile(string $flag): int
    {
        return Artisan::call('outlets:reconcile', [$flag => true, '--dir' => $this->dir]);
    }

    public function test_an_unchanged_database_reconciles_cleanly(): void
    {
        $this->assertSame(0, $this->reconcile('--before'));
        $this->assertSame(0, $this->reconcile('--after'));

        $this->assertSame(0, $this->reconcile('--compare'));
        $this->assertStringContainsString('REKONSILIASI OK', Artisan::output());
    }

    public function test_a_changed_stock_balance_is_reported_and_fails(): void
    {
        $this->reconcile('--before');
        DB::table('product_variants')->orderBy('id')->limit(1)->update(['current_stock' => 49]);
        $this->reconcile('--after');

        $this->assertNotSame(0, $this->reconcile('--compare'));
        $this->assertStringContainsString('stok', Artisan::output());
    }

    public function test_a_lost_or_renamed_outlet_identity_is_reported(): void
    {
        $this->reconcile('--before');
        DB::table($this->outletModel() === \App\Models\Outlet::class ? 'outlets' : 'outlets')->orderBy('id')->limit(1)->update(['name' => 'Berubah']);
        $this->reconcile('--after');

        $this->assertNotSame(0, $this->reconcile('--compare'));
        $this->assertStringContainsString('identitas outlet', Artisan::output());
    }

    public function test_a_changed_row_count_is_reported(): void
    {
        $this->reconcile('--before');
        User::factory()->create();
        $this->reconcile('--after');

        $this->assertNotSame(0, $this->reconcile('--compare'));
        $this->assertStringContainsString('users', Artisan::output());
    }

    public function test_exactly_one_mode_flag_is_required_and_compare_needs_both_snapshots(): void
    {
        $this->assertSame(2, Artisan::call('outlets:reconcile', ['--dir' => $this->dir]));
        $this->assertNotSame(0, $this->reconcile('--compare'));   // belum ada cuplikan
    }

    /** 045 Stage 2 — outlet anak harus sama dengan induknya; selisih dilaporkan walau jumlah dan uang tidak berubah. */
    public function test_an_order_whose_outlet_differs_from_its_shift_fails_the_stage_check(): void
    {
        [$a, $b] = \App\Models\Outlet::orderBy('id')->get()->all();
        $cashier = User::factory()->create(['role' => 'cashier']);
        $shift = $this->shiftAt($a, $cashier);
        $variant = \App\Models\ProductVariant::first();
        $order = $this->orderAt($shift, $variant, 1);

        $this->reconcile('--before');
        $this->reconcile('--after');
        $this->assertSame(0, $this->reconcile('--compare'));

        DB::table('orders')->where('id', $order->id)->update(['outlet_id' => $b->id]);

        $this->assertNotSame(0, $this->reconcile('--compare'));
        $this->assertStringContainsString('berbeda dari shift-nya', Artisan::output());
    }

    public function test_a_payment_whose_outlet_differs_from_its_order_fails_the_stage_check(): void
    {
        [$a, $b] = \App\Models\Outlet::orderBy('id')->get()->all();
        $cashier = User::factory()->create(['role' => 'cashier']);
        $order = $this->orderAt($this->shiftAt($a, $cashier), \App\Models\ProductVariant::first(), 1);

        $this->reconcile('--before');
        $this->reconcile('--after');
        DB::table('payments')->where('order_id', $order->id)->update(['outlet_id' => $b->id]);

        $this->assertNotSame(0, $this->reconcile('--compare'));
        $this->assertStringContainsString('berbeda dari ordernya', Artisan::output());
    }

    /** 045 Stage 3 — setiap pasangan outlet × produk punya baris outlet_item_settings. */
    public function test_a_missing_outlet_item_setting_fails_the_stage_check(): void
    {
        $this->reconcile('--before');
        $this->reconcile('--after');
        $this->assertSame(0, $this->reconcile('--compare'));

        DB::table('outlet_item_settings')->orderBy('id')->limit(1)->delete();

        $this->assertNotSame(0, $this->reconcile('--compare'));
        $this->assertStringContainsString('outlet_item_settings', Artisan::output());
    }

    public function test_an_outlet_price_pointing_at_nothing_fails_the_stage_check(): void
    {
        $this->reconcile('--before');
        $this->reconcile('--after');
        $variantId = (int) \App\Models\ProductVariant::query()->value('id');
        $outletId = (int) \App\Models\Outlet::query()->value('id');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('variant_outlet_prices')->insert(['variant_id' => $variantId + 999, 'outlet_id' => $outletId, 'price' => 1000, 'data_mode' => 'live']);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->assertNotSame(0, $this->reconcile('--compare'));
        $this->assertStringContainsString('harga outlet menunjuk', Artisan::output());
    }
}
