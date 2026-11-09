<?php

namespace Tests\Feature\PreorderBulk;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PaymentChannel;
use App\Models\Preorder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\PreorderService;
use App\Services\StockService;
use Illuminate\Testing\TestResponse;

/**
 * 065 — dunia uji aksi massal pre-order. Pre-order dibuat lewat service yang SAMA dengan aplikasi (bukan insert
 * mentah), lalu didorong ke status/pembayaran yang diinginkan lewat penulis aslinya. Nama method sengaja tidak
 * memakai put/get/seed/call (bentrok dengan TestCase). Satu varian Rp 100.000 per pre-order (qty 1), stok 50.
 */
trait PreorderBulkFixture
{
    /** @return array<string,mixed> */
    protected function buildBulkWorld(): array
    {
        $cat = Category::factory()->create();
        $artist = Artist::factory()->create(['name' => 'Seller Bulk Alfa']);
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $cat->id, 'is_preorder' => true]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'cost_price' => 40000, 'sell_price' => 100000]);
        app(StockService::class)->applyMovement($variant, 'initial', 500);

        return [
            'artist' => $artist, 'variant' => $variant,
            'owner' => User::factory()->create(['role' => 'owner']),
            'cashier' => User::factory()->create(['role' => 'cashier']),
            'channel' => PaymentChannel::factory()->create(),
            'customer' => Customer::factory()->create(['name' => 'Pelanggan Bulk', 'phone' => '0811000111', 'address' => 'Jl. Uji No. 1']),
        ];
    }

    /**
     * Membuat SATU pre-order (total Rp 100.000) dan mendorongnya ke keadaan yang diminta lewat penulis aslinya.
     *
     * @param  string  $status  ordered|dp_paid|arrived|settled|handed_over|cancelled
     */
    protected function bulkPreorder(array $w, string $status = 'ordered', string $fulfillment = 'courier', ?Customer $customer = null, ?float $paid = null): Preorder
    {
        $svc = app(PreorderService::class);
        $po = $svc->create([
            'customer_id' => ($customer ?? $w['customer'])->id,
            'fulfillment' => $fulfillment,
            'items' => [['variant_id' => $w['variant']->id, 'qty' => 1]],
        ], $w['owner']);

        $pay = function (float $amount) use ($po, $w) {
            app(PaymentService::class)->addPayment($po, [
                'method' => 'cash', 'purpose' => 'down_payment', 'amount' => $amount,
            ], $w['owner']);
        };

        $full = in_array($status, ['settled', 'handed_over'], true);
        if ($paid !== null) {
            $pay($paid);
        } elseif ($full) {
            $pay(100000);
        } elseif (in_array($status, ['dp_paid', 'arrived'], true)) {
            $pay(30000);
        }

        $po = $po->fresh();
        foreach (match ($status) {
            'arrived' => ['arrived'], 'settled' => ['arrived'], 'handed_over' => ['arrived', 'handed_over'],
            'cancelled' => ['cancelled'], default => [],
        } as $step) {
            $po = $svc->transitionStatus($po->fresh(['items']), $step, $step === 'cancelled' ? 'uji' : null, $w['owner']);
        }

        return $po->fresh();
    }

    /** @return array<int, Preorder> */
    protected function bulkPreorders(array $w, int $n, string $status = 'ordered', string $fulfillment = 'courier'): array
    {
        return array_map(fn () => $this->bulkPreorder($w, $status, $fulfillment), range(1, $n));
    }

    /** Pengguna dengan role yang hanya memegang menu pre-orders dan aksi yang disebut. */
    protected function bulkUserWith(array $actions, ?int $artistId = null): User
    {
        $role = Role::create([
            'name' => 'Bulk '.uniqid(), 'menu_keys' => ['preorders'],
            'menu_actions' => ['preorders' => $actions], 'is_system' => false,
        ]);

        return User::factory()->create(['role_id' => $role->id, 'artist_id' => $artistId]);
    }

    /** @param  array<int,int|Preorder>  $ids */
    protected function bulkCall(string $action, string $phase, array $ids, array $params = [], array $extra = []): TestResponse
    {
        $ids = array_map(fn ($i) => $i instanceof Preorder ? $i->id : $i, $ids);

        return $this->postJson("/api/v1/preorders/bulk/{$action}/{$phase}", array_merge(['order_ids' => $ids, 'params' => $params], $extra));
    }

    protected function bulkIds(array $preorders): array
    {
        return array_map(fn (Preorder $p) => $p->id, $preorders);
    }
}
