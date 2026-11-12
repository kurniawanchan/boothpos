<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\ActivityLog;
use App\Models\CustomerOrder;
use App\Services\StockService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §8) — header `Idempotency-Key` pada
 * POST /customer/orders.
 *
 * Ketukan ganda / percobaan ulang jaringan harus berakhir pada SATU pesanan:
 * kunci + isi yang sama → 200 dengan pesanan asli; kunci sama + isi berbeda →
 * 409 `idempotency_key_reused`. Kunci berlaku PER AKUN: pelanggan lain yang
 * memakai kunci yang sama membuat pesanannya sendiri dan tidak mengetahui
 * apa pun tentang pesanan orang lain.
 */
class CustomerCheckoutIdempotencyTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCustomerCommerce();
    }

    public function test_the_same_key_and_body_replays_the_original_order(): void
    {
        $w = $this->buildStorefrontWorld();
        [, $token] = $this->customerWithToken();
        $key = $this->newIdempotencyKey();
        $body = $this->checkoutBody([[$w['variantA']->id, 2], [$w['variantB']->id, 1]], ['notes' => 'sekali saja', 'expected_total' => '80000.00']);

        $first = $this->submitCheckout($token, $body, $key)->assertCreated();
        $second = $this->submitCheckout($token, $body, $key)->assertOk();

        $this->assertSame($first->json('data.order_number'), $second->json('data.order_number'));
        $this->assertEquals($first->json('data'), $second->json('data'));

        $this->assertSame(1, DB::table('customer_orders')->count());
        $this->assertSame(2, DB::table('customer_order_items')->count());
        $this->assertSame(1, DB::table('customer_order_status_events')->count());
        $this->assertSame(1, ActivityLog::where('entity_type', 'CustomerOrder')->count());
    }

    public function test_item_order_and_a_stale_expected_total_do_not_change_the_payload_identity(): void
    {
        $w = $this->buildStorefrontWorld();
        [, $token] = $this->customerWithToken();
        $key = $this->newIdempotencyKey();

        $number = $this->submitCheckout($token, $this->checkoutBody([[$w['variantA']->id, 2], [$w['variantB']->id, 1]], ['expected_total' => '80000.00']), $key)
            ->assertCreated()->json('data.order_number');

        // Urutan baris dibalik, id/qty dikirim sebagai teks, expected_total berbeda: isi kanonisnya tetap sama.
        $this->submitCheckout($token, [
            'fulfilment_method' => 'pickup',
            'items' => [['variant_id' => (string) $w['variantB']->id, 'qty' => '1'], ['variant_id' => (string) $w['variantA']->id, 'qty' => '2']],
            'expected_total' => '1.00',
        ], $key)->assertOk()->assertJsonPath('data.order_number', $number);

        $this->assertSame(1, CustomerOrder::count());
    }

    public function test_a_replay_returns_the_original_even_after_price_and_stock_changed(): void
    {
        $w = $this->buildStorefrontWorld();
        [, $token] = $this->customerWithToken();
        $key = $this->newIdempotencyKey();
        $body = $this->checkoutBody([[$w['variantB']->id, 5]], ['expected_total' => '150000.00']);

        $number = $this->submitCheckout($token, $body, $key)->assertCreated()->json('data.order_number');

        $w['variantB']->update(['sell_price' => 99000]);
        app(StockService::class)->applyMovement($w['variantB'], 'adjustment', -5, reason: 'uji');

        $this->submitCheckout($token, $body, $key)->assertOk()
            ->assertJsonPath('data.order_number', $number)
            ->assertJsonPath('data.total', '150000.00');
        $this->assertSame(1, CustomerOrder::count());
    }

    public function test_the_same_key_with_a_different_body_answers_409_and_creates_nothing(): void
    {
        $w = $this->buildStorefrontWorld();
        [$account, $token] = $this->customerWithToken();
        $address = $this->makeCustomerAddress($account, ['is_default' => true]);
        $key = $this->newIdempotencyKey();
        $lines = [[$w['variantA']->id, 2]];

        $this->submitCheckout($token, $this->checkoutBody($lines, ['expected_total' => '50000.00']), $key)->assertCreated();

        $variations = [
            'qty lain' => $this->checkoutBody([[$w['variantA']->id, 3]], ['expected_total' => '75000.00']),
            'barang lain' => $this->checkoutBody([[$w['variantB']->id, 2]], ['expected_total' => '60000.00']),
            'catatan lain' => $this->checkoutBody($lines, ['notes' => 'beda', 'expected_total' => '50000.00']),
            'kontak lain' => $this->checkoutBody($lines, ['contact' => ['phone' => '081299999999'], 'expected_total' => '50000.00']),
            'metode lain' => $this->checkoutBody($lines, ['fulfilment_method' => 'courier', 'address_id' => $address->id, 'expected_total' => '50000.00']),
        ];

        foreach ($variations as $label => $body) {
            $response = $this->submitCheckout($token, $body, $key);
            $this->assertSame(409, $response->status(), $label);
            $this->assertSame('idempotency_key_reused', $response->json('code'), $label);
            $this->assertSame('application/problem+json', $response->headers->get('Content-Type'), $label);
            $this->assertArrayNotHasKey('errors', $response->json(), $label);
        }

        $this->assertSame(1, DB::table('customer_orders')->count());
        $this->assertSame(1, DB::table('customer_order_items')->count());
        $this->assertSame(1, DB::table('customer_order_status_events')->count());
    }

    public function test_the_same_key_from_another_account_creates_its_own_order_and_reveals_nothing(): void
    {
        $w = $this->buildStorefrontWorld();
        [$first, $firstToken] = $this->customerWithToken(['email' => 'pertama@example.test'], ['name' => 'Pelanggan Pertama', 'phone' => '081200000001']);
        [$second, $secondToken] = $this->customerWithToken(['email' => 'kedua@example.test'], ['name' => 'Pelanggan Kedua', 'phone' => '081200000002']);
        $key = $this->newIdempotencyKey();

        $firstNumber = $this->submitCheckout($firstToken, $this->checkoutBody([[$w['variantA']->id, 2]], ['notes' => 'rahasia pertama', 'expected_total' => '50000.00']), $key)
            ->assertCreated()->json('data.order_number');

        // Kunci sama, isi berbeda, AKUN LAIN: bukan replay dan bukan 409, melainkan pesanan baru miliknya.
        $response = $this->submitCheckout($secondToken, $this->checkoutBody([[$w['variantB']->id, 1]], ['expected_total' => '30000.00']), $key);

        $response->assertCreated()
            ->assertJsonPath('data.contact.name', 'Pelanggan Kedua')
            ->assertJsonPath('data.total', '30000.00');
        $secondNumber = $response->json('data.order_number');
        $this->assertNotSame($firstNumber, $secondNumber);
        foreach ([$firstNumber, 'Pelanggan Pertama', 'pertama@example.test', '081200000001', 'rahasia pertama'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }

        $this->assertSame(1, CustomerOrder::where('customer_account_id', $first->id)->count());
        $this->assertSame(1, CustomerOrder::where('customer_account_id', $second->id)->count());

        // Replay akun kedua mengembalikan pesanannya SENDIRI, bukan pesanan akun pertama.
        $this->submitCheckout($secondToken, $this->checkoutBody([[$w['variantB']->id, 1]], ['expected_total' => '30000.00']), $key)
            ->assertOk()->assertJsonPath('data.order_number', $secondNumber);
        $this->assertSame(2, CustomerOrder::count());
    }

    public function test_a_missing_or_malformed_key_answers_422_and_creates_nothing(): void
    {
        $w = $this->buildStorefrontWorld();
        [, $token] = $this->customerWithToken();
        $body = $this->checkoutBody([[$w['variantA']->id, 1]], ['expected_total' => '25000.00']);

        $assertRefused = function ($response, string $label): void {
            $this->assertSame(422, $response->status(), $label);
            $this->assertSame('idempotency_key_required', $response->json('code'), $label);
            $this->assertSame('application/problem+json', $response->headers->get('Content-Type'), $label);
        };

        $assertRefused($this->bearer($token)->withoutHeader('Idempotency-Key')->postJson('/api/v1/customer/orders', $body), 'tanpa header');

        foreach ([
            'terlalu pendek' => str_repeat('a', 15),
            'terlalu panjang' => str_repeat('a', 65),
            'berspasi' => 'kunci dengan spasi 123',
            'karakter terlarang' => 'kunci/dengan/garis-miring-123',
            'baris baru di akhir' => str_repeat('a', 16)."\n",
        ] as $label => $key) {
            $assertRefused($this->submitCheckout($token, $body, $key), $label);
        }

        // Kunci tidak sah mengalahkan galat isi: klien selalu mendapat kode yang sama.
        $assertRefused($this->bearer($token)->withoutHeader('Idempotency-Key')->postJson('/api/v1/customer/orders', []), 'tanpa header dan isi kosong');

        $this->assertSame(0, CustomerOrder::count());

        // Batas panjang yang sah: 16 dan 64 karakter, termasuk `_` dan `-`.
        $this->submitCheckout($token, $body, 'abcDEF_123-45678')->assertCreated();
        $this->submitCheckout($token, $body, str_repeat('Z', 64))->assertCreated();
    }

    public function test_the_database_itself_refuses_a_second_row_for_the_same_account_and_key(): void
    {
        $order = CustomerOrder::factory()->create(['idempotency_key' => 'kunci-yang-sama-1234']);

        $this->expectException(UniqueConstraintViolationException::class);
        CustomerOrder::factory()->create([
            'customer_account_id' => $order->customer_account_id,
            'idempotency_key' => 'kunci-yang-sama-1234',
        ]);
    }

    public function test_a_unique_violation_that_slips_past_the_lookup_is_answered_as_the_replay_not_500(): void
    {
        $w = $this->buildStorefrontWorld();
        [$account, $token] = $this->customerWithToken();
        $body = $this->checkoutBody([[$w['variantA']->id, 1]], ['expected_total' => '25000.00']);

        // Hash muatan yang sah untuk isi ini: diambil dari pesanan sungguhan dengan kunci lain.
        $this->submitCheckout($token, $body)->assertCreated();
        $hash = DB::table('customer_orders')->where('customer_account_id', $account->id)->value('idempotency_hash');

        $key = $this->newIdempotencyKey();

        // Simulasikan persaingan: pesanan dengan (akun, kunci) yang sama muncul tepat SETELAH pencarian
        // idempotensi service dan sebelum insert-nya (mis. permintaan kembar yang lolos dari kunci baris akun).
        CustomerOrder::creating(function () use ($account, $key, $hash) {
            static $done = false;
            if ($done) {
                return;
            }
            $done = true;
            DB::table('customer_orders')->insert([
                'order_number' => 'CO-R1VA000000',
                'customer_account_id' => $account->id,
                'customer_id' => $account->customer_id,
                'fulfilment_method' => 'pickup',
                'status' => 'awaiting_confirmation',
                'contact_name' => 'Saingan', 'contact_phone' => '0800', 'contact_email' => 'saingan@example.test',
                'subtotal' => 25000, 'shipping_cost' => 0, 'total' => 25000,
                'idempotency_key' => $key, 'idempotency_hash' => $hash,
                'data_mode' => 'live', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        try {
            $response = $this->submitCheckout($token, $body, $key);
        } finally {
            CustomerOrder::flushEventListeners(); // pendengar model tidak boleh bocor ke tes lain
        }

        $response->assertOk()->assertJsonPath('data.order_number', 'CO-R1VA000000');

        // Hanya baris "saingan" yang ada untuk kunci itu; permintaan ini tidak meninggalkan apa pun.
        $this->assertSame(1, DB::table('customer_orders')->where('idempotency_key', $key)->count());
        $this->assertSame(2, DB::table('customer_orders')->count());
        $this->assertSame(1, DB::table('customer_order_items')->count());
        $this->assertSame(1, DB::table('customer_order_status_events')->count());
        $this->assertSame(1, ActivityLog::where('entity_type', 'CustomerOrder')->count());
    }

    public function test_a_racing_row_with_a_different_payload_is_answered_as_409_not_500(): void
    {
        $w = $this->buildStorefrontWorld();
        [$account, $token] = $this->customerWithToken();
        $key = $this->newIdempotencyKey();

        CustomerOrder::creating(function () use ($account, $key) {
            static $done = false;
            if ($done) {
                return;
            }
            $done = true;
            DB::table('customer_orders')->insert([
                'order_number' => 'CO-R1VA000001',
                'customer_account_id' => $account->id,
                'customer_id' => $account->customer_id,
                'fulfilment_method' => 'pickup',
                'status' => 'awaiting_confirmation',
                'contact_name' => 'Saingan', 'contact_phone' => '0800', 'contact_email' => 'saingan@example.test',
                'subtotal' => 1, 'shipping_cost' => 0, 'total' => 1,
                'idempotency_key' => $key, 'idempotency_hash' => str_repeat('0', 64),
                'data_mode' => 'live', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        try {
            $response = $this->submitCheckout($token, $this->checkoutBody([[$w['variantA']->id, 1]], ['expected_total' => '25000.00']), $key);
        } finally {
            CustomerOrder::flushEventListeners();
        }

        $response->assertStatus(409)->assertJsonPath('code', 'idempotency_key_reused');
        // Tes berjalan di dalam transaksi: baris "saingan" ikut digulung bersama savepoint service saat 409
        // dilempar (di produksi ia milik transaksi lain yang sudah commit). Yang penting: tidak ada yang setengah jadi.
        $this->assertSame(0, DB::table('customer_orders')->count());
        $this->assertSame(0, DB::table('customer_order_items')->count());
        $this->assertSame(0, DB::table('customer_order_status_events')->count());
    }
}
