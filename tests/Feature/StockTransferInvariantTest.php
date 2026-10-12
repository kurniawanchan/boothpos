<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\MaterialStockService;
use App\Services\StockTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 045 Stage 6 (T087/T088) — invarian I3 atas urutan acak kirim/terima/batal (item DAN bahan), urutan kunci deterministik.
 */
class StockTransferInvariantTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    public function test_i3_holds_over_a_randomised_sequence_for_items_and_materials(): void
    {
        $outlets = $this->makeOutlets(3)->all();
        $p = Product::factory()->create(['home_outlet_id' => $outlets[0]->id, 'category_id' => Category::factory()->create()->id]);
        $variant = $p->variants()->create(['sku' => 'I3-1', 'variant_name' => 'R', 'sell_price' => 1, 'cost_price' => 1, 'current_stock' => 0]);
        $material = Material::factory()->create(['current_stock' => 0]);
        foreach ($outlets as $o) {
            $this->stockAt($variant, $o, 20);
            app(MaterialStockService::class)->applyMovement(material: $material, outletId: $o->id, type: 'adjustment', qtyChange: 12.0);
        }
        $user = User::factory()->create(['role' => 'owner']);
        $svc = app(StockTransferService::class);
        mt_srand(46);

        $invariant = function () use ($variant, $material): array {
            $variantBalances = (int) DB::table('variant_outlet_stock')->where('variant_id', $variant->id)->sum('current_stock');
            $materialBalances = (float) DB::table('material_outlet_stock')->where('material_id', $material->id)->sum('current_stock');
            $transit = ['variant' => 0.0, 'material' => 0.0];
            $short = ['variant' => 0.0, 'material' => 0.0];
            foreach (StockTransfer::with('lines')->get() as $t) {
                foreach ($t->lines as $l) {
                    if ($t->status === 'in_transit') {
                        $transit[$l->kind] += (float) $l->qty_sent;
                    }
                    if ($t->status === 'received') {
                        $short[$l->kind] += $l->shortfall();
                    }
                }
            }

            return [round($variantBalances + $transit['variant'] + $short['variant'], 3), round($materialBalances + $transit['material'] + $short['material'], 3)];
        };

        $expected = $invariant();
        $this->assertSame([60.0, 36.0], $expected);

        for ($i = 0; $i < 40; $i++) {
            $open = StockTransfer::where('status', 'in_transit')->get();
            $action = mt_rand(0, 2);
            try {
                if ($action === 0 || $open->isEmpty()) {
                    $from = $outlets[mt_rand(0, 2)];
                    $to = collect($outlets)->reject(fn ($o) => $o->id === $from->id)->random();
                    $line = mt_rand(0, 1)
                        ? ['kind' => 'variant', 'item_id' => $variant->id, 'qty' => mt_rand(1, 12)]
                        : ['kind' => 'material', 'item_id' => $material->id, 'qty' => mt_rand(1, 90) / 10];
                    $svc->dispatch($user, $from->id, $to->id, [$line]);
                } elseif ($action === 1) {
                    $t = $open->random();
                    $received = [];
                    foreach ($t->lines as $l) {
                        $received[$l->id] = ['qty_received' => round((float) $l->qty_sent * mt_rand(5, 10) / 10, $l->kind === 'variant' ? 0 : 3), 'reason' => 'selisih'];
                    }
                    $svc->receive($t, $user, $received);
                } else {
                    $svc->cancel($open->random(), $user);
                }
            } catch (ValidationException) {
                // saldo asal tidak cukup, atau jumlah pecahan bukan bulat untuk item — ditolak tanpa efek samping
            }

            $this->assertSame($expected, $invariant(), "I3 broken at step {$i}");
            $this->assertSame(0, DB::table('variant_outlet_stock')->where('current_stock', '<', 0)->count());
            $this->assertSame((int) DB::table('variant_outlet_stock')->where('variant_id', $variant->id)->sum('current_stock'), $variant->fresh()->current_stock, 'I2');
        }
    }

    public function test_the_service_processes_lines_in_one_global_order_so_crossing_transfers_lock_alike(): void
    {
        [$a, $b] = $this->makeOutlets(2)->all();
        $p = Product::factory()->create(['home_outlet_id' => $a->id, 'category_id' => Category::factory()->create()->id]);
        $v1 = $p->variants()->create(['sku' => 'LK-1', 'variant_name' => 'R', 'sell_price' => 1, 'cost_price' => 1, 'current_stock' => 0]);
        $v2 = $p->variants()->create(['sku' => 'LK-2', 'variant_name' => 'S', 'sell_price' => 1, 'cost_price' => 1, 'current_stock' => 0]);
        foreach ([$v1, $v2] as $v) {
            $this->stockAt($v, $a, 10);
            $this->stockAt($v, $b, 10);
        }
        $user = User::factory()->create(['role' => 'owner']);
        $locked = [];
        DB::listen(function ($q) use (&$locked) {
            if (stripos($q->sql, 'for update') !== false && str_contains($q->sql, 'from `product_variants`')) {
                $locked[] = $q->bindings[0] ?? null;
            }
        });

        // Dua arah saling silang dengan urutan baris yang DIBALIK pada permintaan kedua: tetap mengunci v1 lalu v2.
        app(StockTransferService::class)->dispatch($user, $a->id, $b->id, [['kind' => 'variant', 'item_id' => $v2->id, 'qty' => 1], ['kind' => 'variant', 'item_id' => $v1->id, 'qty' => 1]]);
        $first = $locked;
        $locked = [];
        app(StockTransferService::class)->dispatch($user, $b->id, $a->id, [['kind' => 'variant', 'item_id' => $v1->id, 'qty' => 1], ['kind' => 'variant', 'item_id' => $v2->id, 'qty' => 1]]);

        $this->assertSame([$v1->id, $v2->id], array_values(array_unique($first)));
        $this->assertSame([$v1->id, $v2->id], array_values(array_unique($locked)));
    }

    public function test_two_dispatches_of_the_last_units_let_exactly_one_through(): void
    {
        [$a, $b, $c] = $this->makeOutlets(3)->all();
        $p = Product::factory()->create(['home_outlet_id' => $a->id, 'category_id' => Category::factory()->create()->id]);
        $v = $p->variants()->create(['sku' => 'LAST-1', 'variant_name' => 'R', 'sell_price' => 1, 'cost_price' => 1, 'current_stock' => 0]);
        $this->stockAt($v, $a, 5);
        $user = User::factory()->create(['role' => 'owner']);
        $ok = 0;

        foreach ([$b, $c] as $dest) {
            try {
                app(StockTransferService::class)->dispatch($user, $a->id, $dest->id, [['kind' => 'variant', 'item_id' => $v->id, 'qty' => 5]]);
                $ok++;
            } catch (ValidationException) {
            }
        }

        $this->assertSame(1, $ok);
        $this->assertSame(1, StockTransfer::count());
    }
}
