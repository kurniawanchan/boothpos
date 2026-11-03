<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Payment;
use App\Models\Preorder;
use App\Models\PreorderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Permintaan 2026-11-02 atas laporan penjualan per produk: filter seller/kategori/produk, nama kategori,
 * penanda varian terlaris, penggabungan pre-order (porsi terbayar saja), dan ekspor Excel.
 */
class SalesByProductFiltersPreorderTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    private Event $event;

    private CashierSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->event = Event::factory()->create(['status' => 'active']);
        $this->session = CashierSession::factory()->create(['event_id' => $this->event->id, 'user_id' => $this->cashier->id, 'status' => 'open']);
        $this->actingAs($this->owner, 'sanctum');
    }

    private function variant(string $product, string $name, Artist $artist, Category $category, int $price = 10000): ProductVariant
    {
        $p = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'name' => $product]);

        return $p->variants()->create(['sku' => 'F'.Str::upper(Str::random(8)), 'variant_name' => $name, 'sell_price' => $price, 'cost_price' => 1000, 'current_stock' => 10000]);
    }

    private function sell(ProductVariant $v, int $qty): void
    {
        app(OrderService::class)->create([
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $v->id, 'qty' => $qty]],
            'payments' => [['method' => 'cash', 'amount' => $qty * (float) $v->sell_price]],
        ], $this->cashier);
    }

    /** Pre-order dengan satu item; `paid` = uang terkumpul. */
    private function preorder(ProductVariant $v, int $qty, float $paid, string $status = 'dp_paid'): Preorder
    {
        $total = $qty * (float) $v->sell_price;
        $po = Preorder::create([
            'preorder_number' => 'PO-'.uniqid(), 'customer_id' => Customer::factory()->create()->id, 'user_id' => $this->owner->id,
            'event_id' => $this->event->id, 'status' => $status, 'subtotal' => $total, 'total_amount' => $total, 'shipping_cost' => 0,
        ]);
        PreorderItem::create([
            'preorder_id' => $po->id, 'variant_id' => $v->id, 'artist_id' => $v->product->artist_id, 'sku_snapshot' => $v->sku,
            'name_snapshot' => $v->variant_name, 'qty' => $qty, 'cost_price' => 1000, 'sell_price' => $v->sell_price, 'line_total' => $total,
        ]);
        if ($paid > 0) {
            Payment::create(['preorder_id' => $po->id, 'method' => 'cash', 'purpose' => 'down_payment', 'amount' => $paid, 'verification' => 'verified', 'paid_at' => now()]);
        }

        return $po;
    }

    private function report(array $q = []): array
    {
        return $this->getJson('/api/v1/reports/sales-by-product?'.http_build_query($q + ['event_id' => $this->event->id]))->assertOk()->json();
    }

    public function test_rows_carry_the_category_name(): void
    {
        $cat = Category::factory()->create(['name' => 'Keychain']);
        $this->sell($this->variant('Charm', 'A', Artist::factory()->create(), $cat), 1);

        $this->assertSame('Keychain', $this->report()['data'][0]['category_name']);
    }

    public function test_filters_by_seller_category_and_product(): void
    {
        $s1 = Artist::factory()->create();
        $s2 = Artist::factory()->create();
        $c1 = Category::factory()->create();
        $c2 = Category::factory()->create();
        $a = $this->variant('Alpha', 'x', $s1, $c1);
        $b = $this->variant('Beta', 'x', $s2, $c2);
        $this->sell($a, 2);
        $this->sell($b, 3);

        $this->assertSame(['Beta'], collect($this->report(['artist_id' => [$s2->id]])['data'])->pluck('product_name')->all());
        $this->assertSame(['Alpha'], collect($this->report(['category_id' => [$c1->id]])['data'])->pluck('product_name')->all());
        $this->assertSame(['Alpha'], collect($this->report(['product_id' => [$a->product_id]])['data'])->pluck('product_name')->all());
        $this->assertCount(2, $this->report()['data']);
        // Pilihan filter tetap mencakup semua penjual/kategori/produk yang punya penjualan.
        $options = $this->report(['artist_id' => [$s2->id]])['options'];
        $this->assertCount(2, $options['artists']);
        $this->assertCount(2, $options['categories']);
        $this->assertCount(2, $options['products']);
    }

    public function test_rows_carry_stock_in_and_remaining_for_the_whole_product(): void
    {
        $cat = Category::factory()->create();
        $artist = Artist::factory()->create();
        $sold = $this->variant('Charm', 'Sold', $artist, $cat);
        $other = $sold->product->variants()->create(['sku' => 'F'.Str::upper(Str::random(8)), 'variant_name' => 'Unsold', 'sell_price' => 10000, 'cost_price' => 1000, 'current_stock' => 0]);
        $stock = app(StockService::class);
        $stock->applyMovement($other, 'initial', 20);
        $stock->applyMovement($other, 'purchase', 5);
        $stock->applyMovement($other, 'adjustment', -3); // koreksi turun: bukan stok masuk
        $this->sell($sold, 4);

        $row = $this->report()['data'][0];

        // Varian terjual: tak ada stok masuk tercatat (stok awal diset langsung), sisa = 10000 - 4.
        $this->assertSame(9996, $row['variants'][0]['stock_remaining']);
        // Produk = SEMUA varian aktif: masuk 20 + 5, sisa 9996 + 22.
        $this->assertSame(25, $row['stock_in']);
        $this->assertSame(10018, $row['stock_remaining']);
        $this->assertSame(25, $this->report()['totals']['stock_in']);
    }

    public function test_the_variant_with_the_most_units_is_marked_and_a_single_variant_product_is_not(): void
    {
        $artist = Artist::factory()->create();
        $cat = Category::factory()->create();
        $p = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $cat->id, 'name' => 'Cats']);
        $mk = fn (string $n) => $p->variants()->create(['sku' => 'C'.Str::upper(Str::random(8)), 'variant_name' => $n, 'sell_price' => 10000, 'cost_price' => 1, 'current_stock' => 999]);
        $this->sell($mk('Black'), 5);
        $this->sell($mk('White'), 2);
        $solo = $this->variant('Solo', 'only', $artist, $cat);
        $this->sell($solo, 4);

        $rows = collect($this->report()['data']);
        $variants = collect($rows->firstWhere('product_name', 'Cats')['variants'])->keyBy('variant_name');

        $this->assertTrue($variants['Black']['is_top']);
        $this->assertFalse($variants['White']['is_top']);
        $this->assertFalse($rows->firstWhere('product_name', 'Solo')['variants'][0]['is_top']);
    }

    public function test_paid_preorders_are_combined_by_their_collected_share_and_unpaid_or_cancelled_are_not(): void
    {
        $artist = Artist::factory()->create();
        $v = $this->variant('Combo', 'x', $artist, Category::factory()->create());
        $this->sell($v, 2);                                   // POS: 2 unit, 20.000
        $this->preorder($v, 4, 20000);                        // 4 × 10.000 = 40.000, terbayar 50% → 2 unit, 20.000
        $this->preorder($v, 5, 0, 'ordered');                 // belum dibayar → belum diakui
        $this->preorder($v, 9, 90000, 'cancelled');           // dibatalkan → tidak ikut

        $row = collect($this->report()['data'])->firstWhere('product_name', 'Combo');

        $this->assertSame(4, $row['units']);
        $this->assertSame(2, $row['pos_units']);
        $this->assertSame(2, $row['preorder_units']);
        $this->assertSame('40000.00', $row['sales']);
        $this->assertSame('20000.00', $row['preorder_sales']);
        $this->assertSame(2, $row['transaction_count'], 'one sale + one recognised pre-order');
        $this->assertSame(4, $row['variants'][0]['units']);
    }

    public function test_the_transaction_list_mixes_sales_and_preorders_newest_first(): void
    {
        $v = $this->variant('Mix', 'x', Artist::factory()->create(), Category::factory()->create());
        $this->sell($v, 1);
        $po = $this->preorder($v, 2, 20000);

        $res = $this->getJson("/api/v1/reports/sales-by-product/{$v->product_id}/transactions?event_id={$this->event->id}")->assertOk()->json();

        $this->assertSame(['preorder', 'sale'], collect($res['data'])->pluck('kind')->sort()->values()->all());
        $this->assertSame(2, $res['meta']['total']);
        $this->assertSame($po->preorder_number, collect($res['data'])->firstWhere('kind', 'preorder')['order_number']);
    }

    public function test_export_returns_a_workbook_for_owner_and_is_forbidden_for_a_cashier(): void
    {
        $this->sell($this->variant('Exp', 'x', Artist::factory()->create(), Category::factory()->create()), 1);

        $res = $this->get('/api/v1/reports/sales-by-product/export?event_id='.$this->event->id)->assertOk();
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('content-type'));

        $this->actingAs($this->cashier, 'sanctum');
        $this->get('/api/v1/reports/sales-by-product/export')->assertForbidden();
    }
}
