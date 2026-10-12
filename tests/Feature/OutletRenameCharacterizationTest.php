<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PreorderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 045 (T007) — KARAKTERISASI angka laporan dan stok SEBELUM penamaan ulang penjual → outlet.
 *
 * Mengunci angka yang BERLAKU HARI INI (bukan yang ideal) lewat nama LAMA (`artists`, `home_outlet_id`, `/reports/outlet-*`).
 * Pada Stage 1 berkas ini dialihkan ke nama baru (`outlets`, `home_outlet_id`, `/reports/outlet-*`) dan semua angka
 * di bawah HARUS tetap sama persis (SC-002, SC-007 untuk riwayat). Bila satu angka berubah saat rename, itu regresi.
 */
class OutletRenameCharacterizationTest extends TestCase
{
    use RefreshDatabase, \Tests\Support\TreatsOrdersAsLegacy;

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

    /** @return array{0: Outlet, 1: ProductVariant} */
    private function seller(string $code, float $price, float $cost): array
    {
        $artist = Outlet::factory()->create(['code' => $code, 'name' => "Seller {$code}"]);
        $product = Product::factory()->create(['home_outlet_id' => $artist->id, 'category_id' => Category::factory()->create()->id]);
        $variant = $product->variants()->create(['sku' => $code.'KYAAA0001', 'sell_price' => $price, 'cost_price' => $cost, 'current_stock' => 1000]);

        return [$artist, $variant];
    }

    private function sale(array $lines)
    {
        $total = collect($lines)->sum(fn ($l) => $l[0]->sell_price * $l[1]);

        return app(OrderService::class)->create([
            'session_id' => $this->session->id,
            'local_ref' => (string) Str::uuid(),
            'items' => collect($lines)->map(fn ($l) => ['variant_id' => $l[0]->id, 'qty' => $l[1]])->all(),
            'payments' => [['method' => 'cash', 'amount' => $total]],
        ], $this->cashier);
    }

    private function scenario(): array
    {
        [$a, $va] = $this->seller('AAA', 10000, 4000);
        [$b, $vb] = $this->seller('BBB', 20000, 9000);

        $this->sale([[$va, 3]]);                  // A: 30.000
        $this->sale([[$va, 1], [$vb, 2]]);        // A: 10.000, B: 40.000
        $voided = $this->sale([[$va, 5]]);        // dibatalkan: tidak boleh terhitung
        app(OrderService::class)->void($voided, 'salah input', $this->owner);

        $service = app(PreorderService::class);
        $preorder = $service->create([
            'event_id' => $this->event->id, 'customer_id' => Customer::factory()->create()->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $vb->id, 'qty' => 1]],
        ], $this->owner);
        $service->recordPayment($preorder, ['method' => 'cash', 'channel_id' => null, 'purpose' => 'down_payment', 'amount' => 10000]);

        return [$a, $b, $va, $vb];
    }

    public function test_the_report_figures_and_stock_sums_are_pinned_before_the_rename(): void
    {
        [$a, $b, $va, $vb] = $this->scenario();

        $recap = collect($this->getJson("/api/v1/reports/outlet-settlements?event_id={$this->event->id}")->assertOk()->json('data'))->keyBy('outlet_id');
        $profit = collect($this->getJson("/api/v1/reports/outlet-profit?event_id={$this->event->id}")->assertOk()->json('data'))->keyBy('outlet_id');
        $stock = collect($this->getJson('/api/v1/reports/stock-by-outlet')->assertOk()->json('data'))->keyBy('outlet_id');

        // Rekap (POS saja — fitur 040): penjualan dibatalkan tidak terhitung; pre-order tidak masuk rekap.
        $this->assertSame(['40000.00', 4], [$recap[$a->id]['total_sales'], $recap[$a->id]['total_units']]);
        $this->assertSame(['40000.00', 2], [$recap[$b->id]['total_sales'], $recap[$b->id]['total_units']]);

        // Modal & laba: POS + bagian pre-order yang SUDAH dibayar (10.000 dari 20.000 = 50 %).
        $this->assertSame(['40000.00', '16000.00', '24000.00', '0.00'], [$profit[$a->id]['total_sales'], $profit[$a->id]['modal'], $profit[$a->id]['gross_profit'], $profit[$a->id]['sales_preorder']]);
        $this->assertSame(
            ['50000.00', '22500.00', '27500.00', '40000.00', '10000.00', '18000.00', '4500.00', '22000.00', '5500.00'],
            [$profit[$b->id]['total_sales'], $profit[$b->id]['modal'], $profit[$b->id]['gross_profit'], $profit[$b->id]['sales_pos'], $profit[$b->id]['sales_preorder'],
                $profit[$b->id]['modal_pos'], $profit[$b->id]['modal_preorder'], $profit[$b->id]['gross_profit_pos'], $profit[$b->id]['gross_profit_preorder']],
        );

        // Stok: A 1000 − 3 − 1 (−5 dikembalikan karena batal); B 1000 − 2; pre-order tidak menyentuh stok.
        // 045 Stage 7: laporan stok membaca saldo PER OUTLET (tempat stok dibukukan), bukan lagi "varian milik penjual";
        // jumlah seluruh outlet tetap sama dengan total tersimpan (996 + 998).
        $this->assertSame(1994, (int) $stock->sum('total_stock'));
        $this->assertSame([996, 998], [$va->fresh()->current_stock, $vb->fresh()->current_stock]);
    }

    public function test_the_item_owner_is_still_the_seller_on_the_product_list(): void
    {
        [$a, $b] = $this->scenario();

        $ids = collect($this->getJson("/api/v1/products?home_outlet_id[]={$a->id}")->assertOk()->json('data'))->pluck('home_outlet_id')->unique()->values()->all();

        $this->assertSame([$a->id], $ids);
    }
}
