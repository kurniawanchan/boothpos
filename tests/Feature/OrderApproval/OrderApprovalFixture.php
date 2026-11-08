<?php

namespace Tests\Feature\OrderApproval;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderService;
use App\Services\StockService;

/**
 * 059 — dunia uji persetujuan: tiga seller (A, B, C), penjualan campuran persis seperti contoh spec
 * (Indomie Goreng + Es Teh = A, Kopi Susu = B, Roti Bakar = C), plus pengguna terikat seller.
 * Data dibuat SEBELUM ada pengguna terautentikasi. Nama method tidak memakai put/get/seed/call
 * (bentrok dengan TestCase).
 */
trait OrderApprovalFixture
{
    /** @return array<string,mixed> */
    protected function buildApprovalWorld(): array
    {
        $cat = Category::factory()->create();
        $a = Artist::factory()->create(['name' => 'Seller Approval Alfa']);
        $b = Artist::factory()->create(['name' => 'Seller Approval Beta']);
        $c = Artist::factory()->create(['name' => 'Seller Approval Gama']);

        $mk = function (Artist $artist, string $name, int $price = 10000) use ($cat) {
            $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $cat->id, 'name' => $name]);
            $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'cost_price' => 1000, 'sell_price' => $price]);
            app(StockService::class)->applyMovement($variant, 'initial', 100);

            return $variant;
        };
        $indomie = $mk($a, 'Indomie Goreng', 5000);
        $esTeh = $mk($a, 'Es Teh', 3000);
        $kopi = $mk($b, 'Kopi Susu', 12000);
        $roti = $mk($c, 'Roti Bakar', 15000);

        $cashier = User::factory()->create(['role' => 'cashier']);
        $session = CashierSession::factory()->create(['user_id' => $cashier->id]);

        $sell = function (array $variants) use ($cashier, $session) {
            $total = collect($variants)->sum(fn ($v) => (float) $v->sell_price);

            return app(OrderService::class)->create([
                'session_id' => $session->id,
                'items' => array_map(fn ($v) => ['variant_id' => $v->id, 'qty' => 1], $variants),
                'payments' => [['method' => 'cash', 'amount' => $total]],
            ], $cashier);
        };

        return [
            'a' => $a, 'b' => $b, 'c' => $c,
            'cashier' => $cashier, 'session' => $session,
            'userA' => User::factory()->create(['role' => 'cashier', 'artist_id' => $a->id]),
            'userB' => User::factory()->create(['role' => 'cashier', 'artist_id' => $b->id]),
            'userC' => User::factory()->create(['role' => 'cashier', 'artist_id' => $c->id]),
            'owner' => User::factory()->create(['role' => 'owner']),
            'admin' => User::factory()->create(['role' => 'admin']),
            'variants' => compact('indomie', 'esTeh', 'kopi', 'roti'),
            'orderA' => $sell([$indomie]),                       // seller tunggal
            'orderMulti' => $sell([$indomie, $esTeh, $kopi, $roti]), // 4 item, 3 seller
            'orderAB' => $sell([$esTeh, $kopi]),                 // 2 seller
            'sell' => $sell,
        ];
    }

    /** @return array{0: string, 1: array} [metode, data multipart] bantu membuat permintaan stempel. */
    protected function stampPayload(int $artistId, string $hash): array
    {
        return ['artist_id' => $artistId, 'method' => 'stamp', 'items_hash' => $hash];
    }

    /** items_hash terkini seorang seller pada sebuah penjualan, lewat endpoint GET (seperti klien sungguhan). */
    protected function currentHash(int $orderId, int $artistId): string
    {
        $sellers = $this->getJson("/api/v1/orders/{$orderId}/approvals")->assertOk()->json('data.sellers');

        return collect($sellers)->firstWhere('artist_id', $artistId)['items_hash'];
    }

    // ------------------------------------------------------------------
    // 064 — persetujuan massal
    // ------------------------------------------------------------------

    /** @return array<int, \App\Models\Order> N penjualan seller tunggal (Indomie = seller A), nomor berbeda. */
    protected function makeSalesOfA(array $w, int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $w['sell']([$w['variants']['indomie']]);
        }

        return $out;
    }

    /** @param array<int,int> $ids */
    protected function bulkPreview(array $ids)
    {
        return $this->postJson('/api/v1/orders/approvals/bulk/preview', ['order_ids' => $ids]);
    }

    /**
     * Terapkan persetujuan massal seperti klien sungguhan: pratinjau dulu (kecuali `$plan` diberikan), lalu multipart
     * dengan `order_ids`/`plan` sebagai satu string JSON per kolom.
     *
     * @param  array<int,int>  $ids
     */
    protected function bulkApply(array $ids, string $method = 'stamp', ?array $plan = null, $image = null)
    {
        $plan ??= $this->bulkPreview($ids)->assertOk()->json('data.plan');
        $data = ['order_ids' => json_encode($ids), 'plan' => json_encode($plan), 'method' => $method];
        if ($image !== null) {
            $data['image'] = $image;
        }

        return $this->post('/api/v1/orders/approvals/bulk', $data, ['Accept' => 'application/json']);
    }
}
