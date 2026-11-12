<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\CustomerOrder;
use App\Services\StockService;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §8) — POST /customer/orders/quote.
 *
 * Quote adalah hitungan server atas keranjang (harga dari `sell_price`,
 * kelayakan dari scope etalase) TANPA efek samping: tidak ada pesanan, tidak
 * ada pergerakan stok, tidak ada log. Angkanya harus sama dengan yang dipakai
 * saat pesanan dibuat.
 */
class CustomerCheckoutQuoteTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    private const URL = '/api/v1/customer/orders/quote';

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCustomerCommerce();
    }

    private function rowCounts(): array
    {
        return [
            'orders' => DB::table('customer_orders')->count(),
            'items' => DB::table('customer_order_items')->count(),
            'events' => DB::table('customer_order_status_events')->count(),
            'movements' => DB::table('stock_movements')->count(),
            'logs' => DB::table('activity_logs')->count(),
        ];
    }

    public function test_pickup_quote_prices_the_cart_from_the_server_and_has_no_side_effects(): void
    {
        $w = $this->buildStorefrontWorld();
        [, $token] = $this->customerWithToken();
        $before = $this->rowCounts();

        $response = $this->bearer($token)->postJson(self::URL, $this->checkoutBody([[$w['variantB']->id, 1], [$w['variantA']->id, 2]]));

        $response->assertOk()->assertExactJson(['data' => [
            'fulfilment_method' => 'pickup',
            // Urutan baris mengikuti urutan keranjang klien.
            'lines' => [
                [
                    'variant_id' => $w['variantB']->id,
                    'product_slug' => 'gantungan-kunci-sakura',
                    'name' => 'Gantungan Kunci Sakura — Biru',
                    'image_url' => null,
                    'unit_price' => '30000.00',
                    'qty' => 1,
                    'line_total' => '30000.00',
                    'max_qty' => 5,
                ],
                [
                    'variant_id' => $w['variantA']->id,
                    'product_slug' => 'gantungan-kunci-sakura',
                    'name' => 'Gantungan Kunci Sakura — Merah',
                    'image_url' => null,
                    'unit_price' => '25000.00',
                    'qty' => 2,
                    'line_total' => '50000.00',
                    'max_qty' => 10,
                ],
            ],
            'subtotal' => '80000.00',
            'shipping_cost' => '0.00',
            'shipping_cost_pending' => false,
            'total' => '80000.00',
        ]]);

        $this->assertSame($before, $this->rowCounts());
        $this->assertSame(10, $w['variantA']->fresh()->current_stock);

        // Tidak ada field staf yang ikut.
        foreach (['cost_price', 'current_stock', 'sku', 'image_path', 'data_mode'] as $leak) {
            $this->assertStringNotContainsString('"'.$leak.'"', $response->getContent());
        }
    }

    public function test_courier_quote_marks_the_shipping_cost_as_pending(): void
    {
        $w = $this->buildStorefrontWorld();
        [$account, $token] = $this->customerWithToken();
        $address = $this->makeCustomerAddress($account, ['is_default' => true]);

        $this->bearer($token)->postJson(self::URL, $this->checkoutBody([[$w['variantA']->id, 1]], ['fulfilment_method' => 'courier', 'address_id' => $address->id]))
            ->assertOk()
            ->assertJsonPath('data.fulfilment_method', 'courier')
            ->assertJsonPath('data.shipping_cost', '0.00')
            ->assertJsonPath('data.shipping_cost_pending', true)
            ->assertJsonPath('data.subtotal', '25000.00')
            ->assertJsonPath('data.total', '25000.00');
    }

    public function test_client_sent_prices_totals_and_expected_total_are_ignored(): void
    {
        $w = $this->buildStorefrontWorld();
        [, $token] = $this->customerWithToken();

        $this->bearer($token)->postJson(self::URL, [
            'fulfilment_method' => 'pickup',
            'items' => [['variant_id' => $w['variantA']->id, 'qty' => 2, 'unit_price' => '1.00', 'line_total' => '2.00', 'name' => 'Karangan']],
            'subtotal' => '2.00',
            'total' => '2.00',
            'shipping_cost' => '-5.00',
            'expected_total' => '2.00',
        ])->assertOk()
            ->assertJsonPath('data.lines.0.unit_price', '25000.00')
            ->assertJsonPath('data.lines.0.name', 'Gantungan Kunci Sakura — Merah')
            ->assertJsonPath('data.total', '50000.00');
    }

    public function test_cart_problems_answer_409_with_one_reason_per_line(): void
    {
        config(['customer_commerce.max_qty_per_line' => 8]);
        $w = $this->buildStorefrontWorld();
        [, $token] = $this->customerWithToken();
        $unknownId = $w['unpublishedProductVariant']->id + 9999;

        $response = $this->bearer($token)->postJson(self::URL, $this->checkoutBody([
            [$w['variantA']->id, 9],                  // di atas batas per baris (8), stok 10
            [$w['variantB']->id, 6],                  // di bawah batas, di atas stok (5)
            [$w['outOfStockVariant']->id, 1],
            [$w['inactiveVariant']->id, 1],
            [$w['unpublishedVariant']->id, 1],
            [$w['unpublishedProductVariant']->id, 1],
            [$unknownId, 1],
        ]));

        $response->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'cart_invalid')
            ->assertJsonPath('status', 409);

        $this->assertEquals([
            ['variant_id' => $w['variantA']->id, 'reason' => 'qty_limit', 'max_qty' => 8],
            ['variant_id' => $w['variantB']->id, 'reason' => 'insufficient_stock', 'max_qty' => 5],
            ['variant_id' => $w['outOfStockVariant']->id, 'reason' => 'unavailable', 'max_qty' => 0],
            ['variant_id' => $w['inactiveVariant']->id, 'reason' => 'unavailable', 'max_qty' => 0],
            ['variant_id' => $w['unpublishedVariant']->id, 'reason' => 'unavailable', 'max_qty' => 0],
            ['variant_id' => $w['unpublishedProductVariant']->id, 'reason' => 'unavailable', 'max_qty' => 0],
            ['variant_id' => $unknownId, 'reason' => 'unavailable', 'max_qty' => 0],
        ], $response->json('errors.items'));

        // Varian tersembunyi dan varian yang tidak ada dijawab SAMA: keberadaannya tidak terbaca.
        $this->assertSame(['type', 'title', 'status', 'detail', 'code', 'errors', 'request_id'], array_keys($response->json()));
        $this->assertSame(['items'], array_keys($response->json('errors')));
    }

    public function test_only_problem_lines_are_reported(): void
    {
        $w = $this->buildStorefrontWorld();
        [, $token] = $this->customerWithToken();

        $response = $this->bearer($token)->postJson(self::URL, $this->checkoutBody([[$w['variantA']->id, 1], [$w['variantB']->id, 6]]));

        $response->assertStatus(409)->assertJsonPath('code', 'cart_invalid')->assertJsonCount(1, 'errors.items');
        $this->assertEquals(['variant_id' => $w['variantB']->id, 'reason' => 'insufficient_stock', 'max_qty' => 5], $response->json('errors.items.0'));
    }

    public function test_a_variant_of_the_other_data_mode_or_a_preorder_product_is_unavailable(): void
    {
        $w = $this->buildStorefrontWorld();
        [, $token] = $this->customerWithToken();

        // Varian DEMO yang layak jual di modenya sendiri: tidak boleh bisa dipesan dari LIVE.
        $demoVariant = ModeGate::runAs('demo', fn () => $this->makeStorefrontProduct(['name' => 'Produk Demo'])[1]);
        [$preorderProduct, $preorderVariant] = $this->makeStorefrontProduct(['name' => 'Produk Pre-order']);
        $preorderProduct->update(['is_preorder' => true]);

        $response = $this->bearer($token)->postJson(self::URL, $this->checkoutBody([[$w['variantA']->id, 1], [$demoVariant->id, 1], [$preorderVariant->id, 1]]));

        $response->assertStatus(409)->assertJsonPath('code', 'cart_invalid');
        $this->assertEquals([
            ['variant_id' => $demoVariant->id, 'reason' => 'unavailable', 'max_qty' => 0],
            ['variant_id' => $preorderVariant->id, 'reason' => 'unavailable', 'max_qty' => 0],
        ], $response->json('errors.items'));
    }

    public function test_shape_errors_are_422(): void
    {
        config(['customer_commerce.max_lines_per_order' => 2]);
        $w = $this->buildStorefrontWorld();
        [, $token] = $this->customerWithToken();
        $a = $w['variantA']->id;
        $b = $w['variantB']->id;

        $cases = [
            'metode tidak dikenal' => [['fulfilment_method' => 'drone', 'items' => [['variant_id' => $a, 'qty' => 1]]], 'fulfilment_method'],
            'metode kosong' => [['items' => [['variant_id' => $a, 'qty' => 1]]], 'fulfilment_method'],
            'tanpa item' => [['fulfilment_method' => 'pickup', 'items' => []], 'items'],
            'item bukan daftar' => [['fulfilment_method' => 'pickup', 'items' => 'semua'], 'items'],
            'terlalu banyak baris' => [['fulfilment_method' => 'pickup', 'items' => [['variant_id' => $a, 'qty' => 1], ['variant_id' => $b, 'qty' => 1], ['variant_id' => $w['outOfStockVariant']->id, 'qty' => 1]]], 'items'],
            'varian kembar' => [['fulfilment_method' => 'pickup', 'items' => [['variant_id' => $a, 'qty' => 1], ['variant_id' => $a, 'qty' => 2]]], 'items.0.variant_id'],
            'qty nol' => [['fulfilment_method' => 'pickup', 'items' => [['variant_id' => $a, 'qty' => 0]]], 'items.0.qty'],
            'qty negatif' => [['fulfilment_method' => 'pickup', 'items' => [['variant_id' => $a, 'qty' => -3]]], 'items.0.qty'],
            'qty pecahan' => [['fulfilment_method' => 'pickup', 'items' => [['variant_id' => $a, 'qty' => 1.5]]], 'items.0.qty'],
            'varian bukan angka' => [['fulfilment_method' => 'pickup', 'items' => [['variant_id' => 'abc', 'qty' => 1]]], 'items.0.variant_id'],
            'kurir tanpa alamat' => [['fulfilment_method' => 'courier', 'items' => [['variant_id' => $a, 'qty' => 1]]], 'address_id'],
            'catatan terlalu panjang' => [['fulfilment_method' => 'pickup', 'items' => [['variant_id' => $a, 'qty' => 1]], 'notes' => str_repeat('n', 501)], 'notes'],
            'kontak bukan objek' => [['fulfilment_method' => 'pickup', 'items' => [['variant_id' => $a, 'qty' => 1]], 'contact' => 'Rina'], 'contact'],
            'telepon terlalu panjang' => [['fulfilment_method' => 'pickup', 'items' => [['variant_id' => $a, 'qty' => 1]], 'contact' => ['phone' => str_repeat('1', 31)]], 'contact.phone'],
        ];

        foreach ($cases as $label => [$body, $field]) {
            $response = $this->bearer($token)->postJson(self::URL, $body);
            $this->assertSame(422, $response->status(), $label);
            $this->assertSame('validation_failed', $response->json('code'), $label);
            $this->assertArrayHasKey($field, $response->json('errors'), $label);
        }
    }

    public function test_another_accounts_address_is_refused_exactly_like_an_unknown_one(): void
    {
        $w = $this->buildStorefrontWorld();
        [$owner] = $this->customerWithToken();
        [, $token] = $this->customerWithToken();
        $foreign = $this->makeCustomerAddress($owner, ['is_default' => true, 'recipient_name' => 'Pemilik Asli', 'address_line' => 'Jl. Rahasia 9']);

        $attempt = fn (int $addressId) => $this->bearer($token)->postJson(
            self::URL,
            $this->checkoutBody([[$w['variantA']->id, 1]], ['fulfilment_method' => 'courier', 'address_id' => $addressId]),
        );

        $foreignResponse = $attempt($foreign->id);
        $unknownResponse = $attempt($foreign->id + 9999);

        $foreignResponse->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['address_id']);
        $this->assertSame(
            collect($unknownResponse->json())->except('request_id')->all(),
            collect($foreignResponse->json())->except('request_id')->all(),
        );
        $this->assertStringNotContainsString('Pemilik Asli', $foreignResponse->getContent());
        $this->assertStringNotContainsString('Jl. Rahasia 9', $foreignResponse->getContent());
    }

    public function test_an_address_is_ignored_for_pickup(): void
    {
        $w = $this->buildStorefrontWorld();
        [$owner] = $this->customerWithToken();
        [, $token] = $this->customerWithToken();
        $foreign = $this->makeCustomerAddress($owner, ['is_default' => true]);

        $this->bearer($token)->postJson(self::URL, $this->checkoutBody([[$w['variantA']->id, 1]], ['address_id' => $foreign->id]))
            ->assertOk()->assertJsonPath('data.fulfilment_method', 'pickup')->assertJsonPath('data.shipping_cost_pending', false);
    }

    public function test_quote_follows_stock_and_price_changes(): void
    {
        $w = $this->buildStorefrontWorld();
        [, $token] = $this->customerWithToken();
        $body = $this->checkoutBody([[$w['variantA']->id, 4]]);

        $this->bearer($token)->postJson(self::URL, $body)->assertOk()->assertJsonPath('data.total', '100000.00');

        $w['variantA']->update(['sell_price' => 27500]);
        app(StockService::class)->applyMovement($w['variantA'], 'adjustment', -7, reason: 'uji');

        $this->bearer($token)->postJson(self::URL, $body)->assertStatus(409)
            ->assertJsonPath('errors.items.0.reason', 'insufficient_stock')
            ->assertJsonPath('errors.items.0.max_qty', 3);

        $this->bearer($token)->postJson(self::URL, $this->checkoutBody([[$w['variantA']->id, 3]]))
            ->assertOk()->assertJsonPath('data.lines.0.unit_price', '27500.00')->assertJsonPath('data.total', '82500.00');
    }

    public function test_quote_needs_a_customer_token_and_the_feature_switch(): void
    {
        $w = $this->buildStorefrontWorld();
        [, $token] = $this->customerWithToken();
        $body = $this->checkoutBody([[$w['variantA']->id, 1]]);

        $this->postJson(self::URL, $body)->assertStatus(401)->assertJsonPath('code', 'unauthenticated');

        $this->disableCustomerCommerce();
        $this->bearer($token)->postJson(self::URL, $body)
            ->assertStatus(503)->assertJsonPath('code', 'customer_commerce_disabled')->assertHeader('Retry-After', '3600');

        $this->assertSame(0, CustomerOrder::count());
    }
}
