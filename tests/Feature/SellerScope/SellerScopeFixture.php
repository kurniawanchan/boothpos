<?php

namespace Tests\Feature\SellerScope;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderService;
use App\Services\StockService;

/**
 * 058 — dunia uji bersama: seller A dan B, produk+varian+stok, penjualan hanya-A, hanya-B, dan campuran A+B.
 * Data dibuat SEBELUM ada pengguna terautentikasi (SellerGate::current() null), lalu tes masuk sebagai
 * pengguna ber-seller. Nama method sengaja tidak memakai put/get/seed/call (bentrok dengan TestCase).
 */
trait SellerScopeFixture
{
    /** @return array<string,mixed> */
    protected function buildSellerWorld(): array
    {
        $cat = Category::factory()->create();
        $a = Artist::factory()->create(['name' => 'Seller Alfa Unik']);
        $b = Artist::factory()->create(['name' => 'Seller Beta Unik']);

        $mk = function (Artist $artist, string $name) use ($cat) {
            $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $cat->id, 'name' => $name]);
            $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'cost_price' => 1000, 'sell_price' => 10000]);
            app(StockService::class)->applyMovement($variant, 'initial', 50);

            return [$product, $variant];
        };
        [$pa, $va] = $mk($a, 'Produk Alfa Unik');
        [$pb, $vb] = $mk($b, 'Produk Beta Unik');

        $cashier = User::factory()->create(['role' => 'cashier']);
        $session = CashierSession::factory()->create(['user_id' => $cashier->id]);
        $customerA = Customer::factory()->create(['name' => 'Pelanggan A']);
        $customerB = Customer::factory()->create(['name' => 'Pelanggan B']);

        $sell = function (array $variants, ?Customer $customer) use ($cashier, $session) {
            $total = count($variants) * 10000;

            return app(OrderService::class)->create([
                'session_id' => $session->id,
                'customer_id' => $customer?->id,
                'items' => array_map(fn ($v) => ['variant_id' => $v->id, 'qty' => 1], $variants),
                'payments' => [['method' => 'cash', 'amount' => $total]],
            ], $cashier);
        };

        return [
            'a' => $a, 'b' => $b,
            'productA' => $pa, 'productB' => $pb,
            'variantA' => $va, 'variantB' => $vb,
            'cashier' => $cashier, 'session' => $session,
            'customerA' => $customerA, 'customerB' => $customerB,
            'orderA' => $sell([$va], $customerA),
            'orderB' => $sell([$vb], $customerB),
            'orderMixed' => $sell([$va, $vb], $customerB),
        ];
    }

    protected function actingAsSellerUser(Artist $artist, string $role = 'cashier'): User
    {
        $user = User::factory()->create(['role' => $role, 'artist_id' => $artist->id]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    /** @return array{pa:int,pb:int,pm:int} id pre-order hanya-A, hanya-B, campuran */
    protected function buildPreorders(array $w): array
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum'); // tanpa seller

        $make = fn (array $variants, $customer) => $this->postJson('/api/v1/preorders', [
            'customer_id' => $customer->id,
            'fulfillment' => 'pickup',
            'event_id' => $w['session']->event_id, // laporan per-seller menggabung events (INNER JOIN), pre-order tanpa event tak ikut
            'items' => array_map(fn ($v) => ['variant_id' => $v->id, 'qty' => 1], $variants),
        ])->assertCreated()->json('id');

        return [
            'pa' => $make([$w['variantA']], $w['customerA']),
            'pb' => $make([$w['variantB']], $w['customerB']),
            'pm' => $make([$w['variantA'], $w['variantB']], $w['customerB']),
        ];
    }
}
