<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\ActivityLog;
use App\Models\CustomerOrder;
use App\Models\Setting;
use App\Services\StockService;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §8) — POST /customer/orders (membuat pesanan).
 *
 * Yang dijaga: harga, total, status, dan pemilik pesanan SELALU dari server;
 * item/kontak/alamat adalah snapshot; pesanan lahir `awaiting_confirmation`
 * dengan tepat satu peristiwa status; dan TIDAK ADA pergerakan stok saat
 * pesanan dibuat (stok baru berkurang saat staf mengonfirmasi).
 */
class CustomerCheckoutTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    private const ORDER_NUMBER = '/^CO-[0-9A-HJKMNP-TV-Z]{10}$/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCustomerCommerce();
    }

    private function nothingWasCreated(): void
    {
        $this->assertSame(0, DB::table('customer_orders')->count());
        $this->assertSame(0, DB::table('customer_order_items')->count());
        $this->assertSame(0, DB::table('customer_order_status_events')->count());
        $this->assertSame(0, ActivityLog::where('entity_type', 'CustomerOrder')->count());
    }

    public function test_pickup_checkout_creates_an_order_awaiting_confirmation(): void
    {
        $w = $this->buildStorefrontWorld();
        Setting::updateOrCreate(['key' => 'customer_commerce_pickup_instructions'], ['value' => 'Ambil di booth A12.']);
        [$account, $token] = $this->customerWithToken(['email' => 'rina@example.test'], ['name' => 'Rina Pelanggan', 'phone' => '081200000001']);
        $movementsBefore = DB::table('stock_movements')->count();

        $response = $this->submitCheckout($token, $this->checkoutBody(
            [[$w['variantA']->id, 2], [$w['variantB']->id, 1]],
            ['notes' => 'Tolong dibungkus kado', 'expected_total' => '80000.00'],
        ));

        $response->assertCreated();
        $order = CustomerOrder::with(['items', 'statusEvents'])->sole();

        $this->assertMatchesRegularExpression(self::ORDER_NUMBER, $order->order_number);
        $response->assertExactJson(['data' => [
            'order_number' => $order->order_number,
            'status' => 'awaiting_confirmation',
            'fulfilment_method' => 'pickup',
            'total' => '80000.00',
            'item_count' => 2,
            'first_item_name' => 'Gantungan Kunci Sakura — Merah',
            'created_at' => $order->created_at->toIso8601String(),
            'updated_at' => $order->updated_at->toIso8601String(),
            'subtotal' => '80000.00',
            'shipping_cost' => '0.00',
            'shipping_cost_pending' => false,
            'contact' => ['name' => 'Rina Pelanggan', 'phone' => '081200000001', 'email' => 'rina@example.test'],
            'shipping_address' => null,
            'notes' => 'Tolong dibungkus kado',
            'cancel_reason' => null,
            'instructions' => 'Ambil di booth A12.',
            'items' => [
                ['name' => 'Gantungan Kunci Sakura — Merah', 'sku' => $w['variantA']->sku, 'unit_price' => '25000.00', 'qty' => 2, 'line_total' => '50000.00'],
                ['name' => 'Gantungan Kunci Sakura — Biru', 'sku' => $w['variantB']->sku, 'unit_price' => '30000.00', 'qty' => 1, 'line_total' => '30000.00'],
            ],
            'shipment' => null,
            'timeline' => [
                ['status' => 'awaiting_confirmation', 'event_type' => 'status_changed', 'note' => null, 'occurred_at' => $order->statusEvents->sole()->occurred_at->toIso8601String()],
            ],
        ]]);

        // Baris pesanan: pemilik dari token, mode data aktif, belum ada stok yang dipotong.
        $this->assertSame($account->id, $order->customer_account_id);
        $this->assertSame($account->customer_id, $order->customer_id);
        $this->assertSame('live', $order->data_mode);
        $this->assertNull($order->stock_deducted_at);
        $this->assertNull($order->confirmed_at);
        $this->assertSame('0.00', $order->shipping_cost);

        // Tepat satu peristiwa status, pelakunya pelanggan, tanpa id staf.
        $event = DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->sole();
        $this->assertSame('awaiting_confirmation', $event->status);
        $this->assertSame('status_changed', $event->event_type);
        $this->assertSame('customer', $event->actor_type);
        $this->assertNull($event->actor_user_id);

        // TIDAK ADA pergerakan stok dan stok tidak berubah.
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertSame(0, DB::table('stock_movements')->where('reference_type', 'customer_order_item')->count());
        $this->assertSame(10, $w['variantA']->fresh()->current_stock);
        $this->assertSame(5, $w['variantB']->fresh()->current_stock);
    }

    public function test_checkout_is_audited_without_a_staff_user_or_personal_data(): void
    {
        $w = $this->buildStorefrontWorld();
        [, $token] = $this->customerWithToken(['email' => 'rina@example.test'], ['name' => 'Rina Pelanggan', 'phone' => '081200000001']);

        $this->submitCheckout($token, $this->checkoutBody([[$w['variantA']->id, 1]], ['notes' => 'catatan pribadi', 'expected_total' => '25000.00']))->assertCreated();

        $order = CustomerOrder::sole();
        $log = ActivityLog::where('entity_type', 'CustomerOrder')->sole();

        $this->assertNull($log->user_id);
        $this->assertSame('customer_order_placed', $log->action);
        $this->assertSame($order->id, $log->entity_id);

        $serialised = json_encode($log->getAttributes());
        foreach (['rina@example.test', 'Rina Pelanggan', '081200000001', 'catatan pribadi'] as $pii) {
            $this->assertStringNotContainsString($pii, $serialised);
        }
    }

    public function test_courier_checkout_snapshots_the_address_and_marks_the_shipping_cost_pending(): void
    {
        $w = $this->buildStorefrontWorld();
        Setting::updateOrCreate(['key' => 'customer_commerce_courier_instructions'], ['value' => 'Ongkir dikonfirmasi toko.']);
        [$account, $token] = $this->customerWithToken(['email' => 'rina@example.test'], ['name' => 'Rina Pelanggan', 'phone' => '081200000001']);
        $address = $this->makeCustomerAddress($account, [
            'is_default' => true, 'label' => 'Rumah', 'recipient_name' => 'Ibu Rina', 'phone' => '081277777777',
            'address_line' => 'Jl. Melati No. 5', 'city' => 'Bandung', 'province' => 'Jawa Barat', 'postal_code' => '40111', 'notes' => 'Pagar hijau',
        ]);

        $response = $this->submitCheckout($token, $this->checkoutBody([[$w['variantA']->id, 1]], [
            'fulfilment_method' => 'courier',
            'address_id' => $address->id,
            'contact' => ['name' => 'Rina (kantor)', 'phone' => '081288888888'],
            'expected_total' => '25000.00',
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.fulfilment_method', 'courier')
            ->assertJsonPath('data.status', 'awaiting_confirmation')
            ->assertJsonPath('data.shipping_cost', '0.00')
            ->assertJsonPath('data.shipping_cost_pending', true)
            ->assertJsonPath('data.total', '25000.00')
            ->assertJsonPath('data.contact', ['name' => 'Rina (kantor)', 'phone' => '081288888888', 'email' => 'rina@example.test'])
            ->assertJsonPath('data.instructions', 'Ongkir dikonfirmasi toko.')
            ->assertJsonPath('data.shipment', null);

        $expectedAddress = [
            'recipient_name' => 'Ibu Rina', 'phone' => '081277777777', 'address_line' => 'Jl. Melati No. 5',
            'city' => 'Bandung', 'province' => 'Jawa Barat', 'postal_code' => '40111', 'notes' => 'Pagar hijau',
        ];
        $this->assertSame($expectedAddress, $response->json('data.shipping_address'));

        // Kontak di pesanan tidak mengubah profil.
        $this->assertDatabaseHas('customers', ['id' => $account->customer_id, 'name' => 'Rina Pelanggan', 'phone' => '081200000001']);

        // Snapshot: mengubah lalu menghapus alamat tersimpan tidak mengubah pesanan.
        $address->update(['address_line' => 'Jl. Pindah No. 1', 'city' => 'Cimahi']);
        $number = $response->json('data.order_number');
        $this->bearer($token)->getJson("/api/v1/customer/orders/{$number}")->assertOk()->assertJsonPath('data.shipping_address', $expectedAddress);
        $address->delete();
        $this->bearer($token)->getJson("/api/v1/customer/orders/{$number}")->assertOk()->assertJsonPath('data.shipping_address', $expectedAddress);
    }

    public function test_item_snapshots_survive_a_later_rename_and_reprice(): void
    {
        $w = $this->buildStorefrontWorld();
        [, $token] = $this->customerWithToken();
        $sku = $w['variantA']->sku;

        $number = $this->submitCheckout($token, $this->checkoutBody([[$w['variantA']->id, 2]], ['expected_total' => '50000.00']))
            ->assertCreated()->json('data.order_number');

        $w['product']->update(['name' => 'Nama Baru Sekali']);
        $w['variantA']->update(['variant_name' => 'Jingga', 'sell_price' => 99000]);

        $this->bearer($token)->getJson("/api/v1/customer/orders/{$number}")->assertOk()
            ->assertJsonPath('data.items.0', ['name' => 'Gantungan Kunci Sakura — Merah', 'sku' => $sku, 'unit_price' => '25000.00', 'qty' => 2, 'line_total' => '50000.00'])
            ->assertJsonPath('data.first_item_name', 'Gantungan Kunci Sakura — Merah')
            ->assertJsonPath('data.subtotal', '50000.00')
            ->assertJsonPath('data.total', '50000.00');
    }

    public function test_the_contact_defaults_to_the_profile_and_blank_values_fall_back(): void
    {
        $w = $this->buildStorefrontWorld();
        [, $token] = $this->customerWithToken(['email' => 'rina@example.test'], ['name' => 'Rina Pelanggan', 'phone' => '081200000001']);

        $this->submitCheckout($token, $this->checkoutBody([[$w['variantA']->id, 1]], [
            'contact' => ['name' => '   ', 'phone' => ''], 'notes' => '   ', 'expected_total' => '25000.00',
        ]))->assertCreated()
            ->assertJsonPath('data.contact', ['name' => 'Rina Pelanggan', 'phone' => '081200000001', 'email' => 'rina@example.test'])
            ->assertJsonPath('data.notes', null);
    }

    public function test_a_phone_number_is_required_in_the_end_result(): void
    {
        $w = $this->buildStorefrontWorld();
        [, $token] = $this->customerWithToken([], ['name' => 'Tanpa Telepon', 'phone' => null]);
        $body = $this->checkoutBody([[$w['variantA']->id, 1]], ['expected_total' => '25000.00']);

        $this->submitCheckout($token, $body)
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['contact.phone']);
        $this->nothingWasCreated();

        // Quote tidak menuntut telepon (hanya menghitung keranjang) …
        $this->bearer($token)->postJson('/api/v1/customer/orders/quote', $body)->assertOk();

        // … dan telepon di `contact` cukup untuk membuat pesanan.
        $this->submitCheckout($token, $body + ['contact' => ['phone' => '081233333333']])
            ->assertCreated()->assertJsonPath('data.contact.phone', '081233333333')->assertJsonPath('data.contact.name', 'Tanpa Telepon');
    }

    public function test_client_sent_prices_totals_status_and_owner_ids_are_ignored(): void
    {
        $w = $this->buildStorefrontWorld();
        [$account, $token] = $this->customerWithToken();
        [$victim] = $this->customerWithToken();

        $response = $this->submitCheckout($token, [
            'fulfilment_method' => 'pickup',
            'items' => [['variant_id' => $w['variantA']->id, 'qty' => 2, 'unit_price' => '1.00', 'line_total' => '2.00', 'sku' => 'PALSU', 'name' => 'Karangan']],
            'expected_total' => '50000.00',
            'subtotal' => '2.00',
            'total' => '2.00',
            'shipping_cost' => '-100.00',
            'status' => 'delivered',
            'order_number' => 'CO-0000000000',
            'customer_account_id' => $victim->id,
            'customer_id' => $victim->customer_id,
            'data_mode' => 'demo',
            'stock_deducted_at' => now()->toIso8601String(),
            'confirmed_at' => now()->toIso8601String(),
            'contact' => ['name' => 'Rina', 'phone' => '0812', 'email' => 'orang-lain@example.test'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_confirmation')
            ->assertJsonPath('data.total', '50000.00')
            ->assertJsonPath('data.items.0.unit_price', '25000.00')
            ->assertJsonPath('data.items.0.name', 'Gantungan Kunci Sakura — Merah')
            ->assertJsonPath('data.contact.email', $account->email);

        $order = CustomerOrder::sole();
        $this->assertNotSame('CO-0000000000', $order->order_number);
        $this->assertSame($account->id, $order->customer_account_id);
        $this->assertSame($account->customer_id, $order->customer_id);
        $this->assertSame('live', $order->data_mode);
        $this->assertNull($order->stock_deducted_at);
        $this->assertNull($order->confirmed_at);
        $this->assertSame(0, CustomerOrder::where('customer_account_id', $victim->id)->count());
    }

    public function test_an_expected_total_mismatch_answers_409_with_a_fresh_quote_and_creates_nothing(): void
    {
        $w = $this->buildStorefrontWorld();
        [, $token] = $this->customerWithToken();
        $body = $this->checkoutBody([[$w['variantA']->id, 2]], ['expected_total' => '50000.00']);

        // Harga naik setelah pelanggan melihat ringkasan.
        $w['variantA']->update(['sell_price' => 26000]);

        $response = $this->submitCheckout($token, $body);

        $response->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'price_changed')
            ->assertJsonPath('errors.quote.total', '52000.00')
            ->assertJsonPath('errors.quote.subtotal', '52000.00')
            ->assertJsonPath('errors.quote.lines.0.unit_price', '26000.00')
            ->assertJsonPath('errors.quote.lines.0.variant_id', $w['variantA']->id);
        $this->assertSame(
            ['fulfilment_method', 'lines', 'subtotal', 'shipping_cost', 'shipping_cost_pending', 'total'],
            array_keys($response->json('errors.quote')),
        );
        $this->nothingWasCreated();

        // Quote di galat sama persis dengan quote biasa, dan total barunya diterima.
        $quote = $this->bearer($token)->postJson('/api/v1/customer/orders/quote', $body)->assertOk()->json('data');
        $this->assertEquals($quote, $response->json('errors.quote'));

        $this->submitCheckout($token, ['expected_total' => $quote['total']] + $body)->assertCreated()->assertJsonPath('data.total', '52000.00');
    }

    public function test_expected_total_is_required_and_must_be_a_money_value(): void
    {
        $w = $this->buildStorefrontWorld();
        [, $token] = $this->customerWithToken();
        $body = $this->checkoutBody([[$w['variantA']->id, 1]]);

        $this->submitCheckout($token, $body)->assertStatus(422)->assertJsonValidationErrors(['expected_total']);
        foreach (['banyak', '-25000.00', '25000.001', '1e5', ['25000.00']] as $bad) {
            $this->submitCheckout($token, $body + ['expected_total' => $bad])->assertStatus(422)->assertJsonValidationErrors(['expected_total']);
        }
        $this->nothingWasCreated();

        // Bentuk tanpa desimal dan angka JSON tetap diterima bila nilainya sama.
        $this->submitCheckout($token, $body + ['expected_total' => '25000'])->assertCreated();
        $this->submitCheckout($token, $body + ['expected_total' => 25000])->assertCreated();
    }

    public function test_unavailable_lines_answer_409_cart_invalid_and_create_nothing(): void
    {
        config(['customer_commerce.max_qty_per_line' => 8]);
        $w = $this->buildStorefrontWorld();
        [, $token] = $this->customerWithToken();

        $response = $this->submitCheckout($token, $this->checkoutBody([
            [$w['variantA']->id, 9],
            [$w['variantB']->id, 6],
            [$w['outOfStockVariant']->id, 1],
            [$w['inactiveVariant']->id, 1],
            [$w['unpublishedVariant']->id, 1],
            [$w['unpublishedProductVariant']->id, 1],
        ], ['expected_total' => '1.00']));

        $response->assertStatus(409)->assertJsonPath('code', 'cart_invalid');
        $this->assertEquals([
            ['variant_id' => $w['variantA']->id, 'reason' => 'qty_limit', 'max_qty' => 8],
            ['variant_id' => $w['variantB']->id, 'reason' => 'insufficient_stock', 'max_qty' => 5],
            ['variant_id' => $w['outOfStockVariant']->id, 'reason' => 'unavailable', 'max_qty' => 0],
            ['variant_id' => $w['inactiveVariant']->id, 'reason' => 'unavailable', 'max_qty' => 0],
            ['variant_id' => $w['unpublishedVariant']->id, 'reason' => 'unavailable', 'max_qty' => 0],
            ['variant_id' => $w['unpublishedProductVariant']->id, 'reason' => 'unavailable', 'max_qty' => 0],
        ], $response->json('errors.items'));
        $this->nothingWasCreated();
    }

    public function test_the_oversell_guard_refuses_more_than_the_stock_on_hand(): void
    {
        [, $variant] = $this->makeStorefrontProduct(['name' => 'Stok Tunggal'], price: 10000, stock: 1);
        [, $token] = $this->customerWithToken();

        $this->submitCheckout($token, $this->checkoutBody([[$variant->id, 2]], ['expected_total' => '20000.00']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'cart_invalid')
            ->assertJsonPath('errors.items.0', ['variant_id' => $variant->id, 'reason' => 'insufficient_stock', 'max_qty' => 1]);
        $this->nothingWasCreated();

        // Jumlah yang ada tetap bisa dipesan, dan stok tetap 1 (belum dipotong).
        $this->submitCheckout($token, $this->checkoutBody([[$variant->id, 1]], ['expected_total' => '10000.00']))->assertCreated();
        $this->assertSame(1, $variant->fresh()->current_stock);
    }

    public function test_a_variant_that_sold_out_after_the_quote_is_refused_at_checkout(): void
    {
        $w = $this->buildStorefrontWorld();
        [, $token] = $this->customerWithToken();
        $body = $this->checkoutBody([[$w['variantB']->id, 5]], ['expected_total' => '150000.00']);
        $this->bearer($token)->postJson('/api/v1/customer/orders/quote', $body)->assertOk();

        app(StockService::class)->applyMovement($w['variantB'], 'adjustment', -5, reason: 'uji');

        $this->submitCheckout($token, $body)->assertStatus(409)
            ->assertJsonPath('code', 'cart_invalid')
            ->assertJsonPath('errors.items.0', ['variant_id' => $w['variantB']->id, 'reason' => 'unavailable', 'max_qty' => 0]);
        $this->nothingWasCreated();
    }

    public function test_checkout_locks_the_account_row_then_the_variant_rows_in_id_order_and_quote_locks_nothing(): void
    {
        $w = $this->buildStorefrontWorld();
        [, $token] = $this->customerWithToken();

        $locks = [];
        DB::listen(function ($query) use (&$locks) {
            if (stripos($query->sql, 'for update') !== false) {
                $locks[] = $query->sql;
            }
        });

        // Keranjang sengaja dikirim dengan id MENURUN: urutan kunci tidak boleh mengikuti urutan klien.
        $lines = [[$w['variantB']->id, 1], [$w['variantA']->id, 1]];
        $this->assertGreaterThan($w['variantA']->id, $w['variantB']->id);

        $this->bearer($token)->postJson('/api/v1/customer/orders/quote', $this->checkoutBody($lines))->assertOk();
        $this->assertSame([], $locks, 'Quote tidak boleh mengunci baris apa pun.');

        $this->submitCheckout($token, $this->checkoutBody($lines, ['expected_total' => '55000.00']))->assertCreated();

        $this->assertCount(2, $locks);
        $this->assertStringContainsString('from `customer_accounts`', $locks[0]);
        $this->assertStringContainsString('from `product_variants`', $locks[1]);
        $this->assertMatchesRegularExpression('/order by `(product_variants`\.`)?id` asc/', $locks[1]);
        // Syarat produk berupa subquery EXISTS: baris produk tidak ikut dikunci.
        $this->assertStringNotContainsString('join', strtolower($locks[1]));
    }

    public function test_duplicate_variant_lines_are_rejected(): void
    {
        $w = $this->buildStorefrontWorld();
        [, $token] = $this->customerWithToken();

        $this->submitCheckout($token, $this->checkoutBody([[$w['variantA']->id, 1], [$w['variantA']->id, 2]], ['expected_total' => '75000.00']))
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['items.0.variant_id']);
        $this->nothingWasCreated();
    }

    public function test_another_accounts_address_cannot_be_used_and_is_not_revealed(): void
    {
        $w = $this->buildStorefrontWorld();
        [$owner] = $this->customerWithToken();
        [, $token] = $this->customerWithToken();
        $foreign = $this->makeCustomerAddress($owner, ['is_default' => true, 'recipient_name' => 'Pemilik Asli', 'address_line' => 'Jl. Rahasia 9']);

        $attempt = fn (int $addressId) => $this->submitCheckout($token, $this->checkoutBody([[$w['variantA']->id, 1]], [
            'fulfilment_method' => 'courier', 'address_id' => $addressId, 'expected_total' => '25000.00',
        ]));

        $foreignResponse = $attempt($foreign->id);
        $unknownResponse = $attempt($foreign->id + 9999);

        $foreignResponse->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['address_id']);
        $this->assertSame(
            collect($unknownResponse->json())->except('request_id')->all(),
            collect($foreignResponse->json())->except('request_id')->all(),
        );
        $this->assertStringNotContainsString('Pemilik Asli', $foreignResponse->getContent());
        $this->assertStringNotContainsString('Jl. Rahasia 9', $foreignResponse->getContent());
        $this->nothingWasCreated();
    }

    public function test_orders_are_stamped_with_the_active_data_mode_and_invisible_from_the_other(): void
    {
        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'demo']);
        [, $demoVariant] = $this->makeStorefrontProduct(['name' => 'Produk Demo'], price: 15000, stock: 3);
        [$demoAccount, $demoToken] = $this->customerWithToken();
        $this->assertSame('demo', $demoAccount->data_mode);

        $number = $this->submitCheckout($demoToken, $this->checkoutBody([[$demoVariant->id, 1]], ['expected_total' => '15000.00']))
            ->assertCreated()->json('data.order_number');

        $this->assertSame('demo', DB::table('customer_orders')->where('order_number', $number)->value('data_mode'));
        $this->bearer($demoToken)->getJson("/api/v1/customer/orders/{$number}")->assertOk();

        // Toko dipindah ke LIVE: pesanan DEMO tidak terlihat oleh query model, dan token DEMO tidak lagi sah.
        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'live']);
        $this->assertTrue(ModeGate::isLive());
        $this->assertSame(0, CustomerOrder::count());
        $this->assertSame(1, CustomerOrder::withoutGlobalScopes()->count());
        $this->bearer($demoToken)->getJson("/api/v1/customer/orders/{$number}")->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->bearer($demoToken)->getJson('/api/v1/customer/orders')->assertStatus(401);

        // Akun LIVE tidak bisa membuka nomor pesanan DEMO (404 biasa) dan tidak bisa memesan varian DEMO.
        [, $liveToken] = $this->customerWithToken();
        $this->bearer($liveToken)->getJson("/api/v1/customer/orders/{$number}")->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->submitCheckout($liveToken, $this->checkoutBody([[$demoVariant->id, 1]], ['expected_total' => '15000.00']))
            ->assertStatus(409)->assertJsonPath('errors.items.0.reason', 'unavailable');
        $this->assertSame(1, CustomerOrder::withoutGlobalScopes()->count());
    }

    public function test_checkout_requires_a_customer_token(): void
    {
        $w = $this->buildStorefrontWorld();

        $this->withHeader('Idempotency-Key', $this->newIdempotencyKey())
            ->postJson('/api/v1/customer/orders', $this->checkoutBody([[$w['variantA']->id, 1]], ['expected_total' => '25000.00']))
            ->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->nothingWasCreated();
    }

    public function test_checkout_is_blocked_while_the_feature_is_off(): void
    {
        $w = $this->buildStorefrontWorld();
        [, $token] = $this->customerWithToken();
        $this->disableCustomerCommerce();

        $this->submitCheckout($token, $this->checkoutBody([[$w['variantA']->id, 1]], ['expected_total' => '25000.00']))
            ->assertStatus(503)->assertJsonPath('code', 'customer_commerce_disabled')->assertHeader('Retry-After', '3600');
        $this->nothingWasCreated();
    }

    public function test_the_checkout_limiter_answers_429(): void
    {
        config(['customer_commerce.rate_limits.checkout.per_account' => 2]);
        $w = $this->buildStorefrontWorld();
        [, $token] = $this->customerWithToken();
        [, $otherToken] = $this->customerWithToken();
        $body = $this->checkoutBody([[$w['variantA']->id, 1]], ['expected_total' => '25000.00']);

        $this->submitCheckout($token, $body)->assertCreated();
        $this->submitCheckout($token, $body)->assertCreated();

        $response = $this->submitCheckout($token, $body);
        $response->assertStatus(429)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'too_many_requests');
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertSame(2, CustomerOrder::count());

        // Ember per AKUN: pelanggan lain tidak ikut terkunci, dan rute lain akun yang sama tetap jalan.
        $this->submitCheckout($otherToken, $body)->assertCreated();
        $this->bearer($token)->getJson('/api/v1/customer/orders')->assertOk();
        $this->bearer($token)->postJson('/api/v1/customer/orders/quote', $body)->assertOk();
    }
}
