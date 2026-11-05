<?php

namespace Tests\Feature\SellerScope;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\OrderService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 058 (T043) — filter seller menambah subquery, bukan query per baris: jumlah query tidak tumbuh seiring jumlah data. */
class QueryCountTest extends TestCase
{
    use RefreshDatabase, SellerScopeFixture;

    private function queries(string $url, \App\Models\User $user): int
    {
        // Pengguna segar tiap pengukuran: pencarian peran (1 query/permintaan) harus sama di kedua sisi perbandingan.
        $this->actingAs($user->fresh(), 'sanctum');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($url)->assertOk();
        $log = DB::getQueryLog();
        $n = count($log);
        DB::disableQueryLog();

        return $n;
    }

    public function test_query_count_does_not_grow_with_rows_for_a_scoped_user(): void
    {
        $w = $this->buildSellerWorld();
        $user = $this->actingAsSellerUser($w['a'], 'admin');
        $urls = ['/api/v1/orders?per_page=100', '/api/v1/products?per_page=100&with_variants=1', '/api/v1/stock/movements?per_page=100', '/api/v1/reports/sales'];

        // Tambah data milik seller A (dibuat tanpa seller). Beberapa eager-load hanya jalan bila relasinya ada, jadi
        // ukur dari kondisi "tunak" (sesudah 2 baris tambahan) lalu bandingkan dengan 6 baris tambahan lagi.
        $addRows = function (int $n) use ($w, $user) {
            $this->actingAs(\App\Models\User::factory()->create(['role' => 'admin']), 'sanctum');
            for ($i = 0; $i < $n; $i++) {
                $p = Product::factory()->create(['artist_id' => $w['a']->id, 'category_id' => $w['productA']->category_id]);
                $v = ProductVariant::factory()->create(['product_id' => $p->id, 'sell_price' => 10000, 'cost_price' => 1000]);
                app(StockService::class)->applyMovement($v, 'initial', 10);
                app(OrderService::class)->create([
                    'session_id' => $w['session']->id,
                    'items' => [['variant_id' => $v->id, 'qty' => 1]],
                    'payments' => [['method' => 'cash', 'amount' => 10000]],
                ], $w['cashier']);
            }
            $this->actingAs($user->fresh(), 'sanctum');
        };

        $addRows(2);
        foreach ($urls as $u) {
            $this->queries($u, $user); // pemanasan: cache pengaturan/lisensi terisi pada panggilan pertama
        }
        $before = array_map(fn ($u) => $this->queries($u, $user), $urls);

        $addRows(6);
        $after = array_map(fn ($u) => $this->queries($u, $user), $urls);

        foreach ($urls as $i => $u) {
            $this->assertSame($before[$i], $after[$i], "{$u}: query bertambah ({$before[$i]} → {$after[$i]}) seiring jumlah baris");
        }
    }
}
