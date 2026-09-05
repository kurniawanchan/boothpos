<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\CashierSession;
use App\Models\Event;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private CashierSession $session;
    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $this->session = CashierSession::factory()->create([
            'event_id' => $event->id, 'user_id' => $this->cashier->id, 'status' => 'open',
        ]);

        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $this->variant = $product->variants()->create([
            'sku' => 'RYUKYSAK0001', 'sell_price' => 25000, 'cost_price' => 10000, 'current_stock' => 10,
        ]);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'session_id' => $this->session->id,
            'local_ref' => (string) \Illuminate\Support\Str::uuid(),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 50000]],
        ], $overrides);
    }

    // --- Positive ---------------------------------------------------

    public function test_cash_order_succeeds_and_reduces_stock(): void
    {
        $response = $this->postJson('/api/v1/orders', $this->basePayload());

        $response->assertCreated()->assertJsonPath('total_amount', '50000.00');
        $this->assertSame(8, $this->variant->fresh()->current_stock);
        $this->assertDatabaseHas('stock_movements', ['variant_id' => $this->variant->id, 'type' => 'sale', 'qty_change' => -2]);
    }

    public function test_order_price_uses_server_master_data_not_client_input(): void
    {
        // Klien mencoba mengirim variant_id yang benar tapi tidak ada
        // field harga di payload sama sekali — dan memang tidak bisa,
        // karena rule tidak mendefinisikan field harga pada items.*.
        // Uji ini memastikan total dihitung dari sell_price server (25000),
        // bukan angka lain manapun.
        $response = $this->postJson('/api/v1/orders', $this->basePayload([
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 25000]],
        ]));

        $response->assertCreated()->assertJsonPath('total_amount', '25000.00');
    }

    // --- Negative: idempotensi -------------------------------------

    public function test_duplicate_local_ref_returns_existing_order_not_a_new_one(): void
    {
        $payload = $this->basePayload();

        $first = $this->postJson('/api/v1/orders', $payload)->json('id');
        $second = $this->postJson('/api/v1/orders', $payload)->json('id');

        $this->assertSame($first, $second);
        $this->assertSame(1, \App\Models\Order::count());
        // Stok hanya berkurang SEKALI meski request dikirim dua kali.
        $this->assertSame(8, $this->variant->fresh()->current_stock);
    }

    // --- Negative: stok --------------------------------------------

    public function test_order_rejected_when_stock_insufficient(): void
    {
        $response = $this->postJson('/api/v1/orders', $this->basePayload([
            'items' => [['variant_id' => $this->variant->id, 'qty' => 999]],
            'payments' => [['method' => 'cash', 'amount' => 999 * 25000]],
        ]));

        $response->assertStatus(409);
        $this->assertSame(10, $this->variant->fresh()->current_stock); // tidak berubah sama sekali
    }

    // --- Negative: pembayaran ----------------------------------------

    public function test_order_rejected_when_payment_does_not_cover_total(): void
    {
        $response = $this->postJson('/api/v1/orders', $this->basePayload([
            'payments' => [['method' => 'cash', 'amount' => 10000]], // total seharusnya 50000
        ]));

        $response->assertStatus(409);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_non_cash_payment_without_proof_token_is_rejected(): void
    {
        $channel = PaymentChannel::factory()->create(['type' => 'bank_transfer']);

        $response = $this->postJson('/api/v1/orders', $this->basePayload([
            'payments' => [['method' => 'bank_transfer', 'channel_id' => $channel->id, 'amount' => 50000]],
        ]));

        $response->assertStatus(409);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_non_cash_payment_with_valid_proof_token_succeeds(): void
    {
        $channel = PaymentChannel::factory()->create(['type' => 'bank_transfer']);
        $proofResponse = $this->postJson('/api/v1/payment-proofs', [
            'file' => \Illuminate\Http\UploadedFile::fake()->image('bukti.jpg'),
            'captured_via' => 'upload',
        ]);
        $token = $proofResponse->json('proof_token');

        $response = $this->postJson('/api/v1/orders', $this->basePayload([
            'payments' => [[
                'method' => 'bank_transfer', 'channel_id' => $channel->id,
                'amount' => 50000, 'proof_token' => $token,
            ]],
        ]));

        $response->assertCreated();
        $this->assertDatabaseHas('payment_proofs', ['proof_token' => $token]);
        $this->assertDatabaseMissing('payment_proofs', ['proof_token' => $token, 'payment_id' => null]);
    }

    public function test_reusing_a_consumed_proof_token_is_rejected(): void
    {
        $channel = PaymentChannel::factory()->create(['type' => 'bank_transfer']);
        $token = $this->postJson('/api/v1/payment-proofs', [
            'file' => \Illuminate\Http\UploadedFile::fake()->image('bukti.jpg'),
            'captured_via' => 'upload',
        ])->json('proof_token');

        $paymentPayload = ['method' => 'bank_transfer', 'channel_id' => $channel->id, 'amount' => 50000, 'proof_token' => $token];

        $this->postJson('/api/v1/orders', $this->basePayload(['payments' => [$paymentPayload]]))->assertCreated();

        // local_ref baru supaya tidak kena jalur idempotensi, tapi
        // proof_token yang SAMA dipakai lagi.
        $response = $this->postJson('/api/v1/orders', $this->basePayload([
            'local_ref' => (string) \Illuminate\Support\Str::uuid(),
            'payments' => [$paymentPayload],
        ]));

        $response->assertStatus(409);
    }

    // --- Diskon (security review 2026-09-05, SEC-H1/SEC-H2) -----------

    public function test_per_item_discount_reduces_total_amount_and_matches_line_total(): void
    {
        // variant sell_price 25000 x qty 2 = 50000 mentah; diskon item
        // 20000 => line_total 30000. Sebelum SEC-H1 diperbaiki,
        // total_amount tetap 50000 (mengabaikan diskon item) meski
        // order_items.line_total sudah 30000 — nilainya saling berbeda.
        $response = $this->postJson('/api/v1/orders', $this->basePayload([
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2, 'discount_amount' => 20000]],
            'payments' => [['method' => 'cash', 'amount' => 30000]],
        ]));

        $response->assertCreated()
            ->assertJsonPath('total_amount', '30000.00')
            ->assertJsonPath('subtotal', '30000.00');
        $this->assertDatabaseHas('order_items', [
            'variant_id' => $this->variant->id,
            'discount_amount' => '20000.00',
            'line_total' => '30000.00',
        ]);
    }

    public function test_per_item_discount_exceeding_line_value_is_rejected(): void
    {
        // SEC-H2 — diskon item (60000) melebihi nilai barisnya sendiri
        // (25000 x 2 = 50000), yang sebelumnya hanya divalidasi 'min:0'
        // tanpa batas atas.
        $response = $this->postJson('/api/v1/orders', $this->basePayload([
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2, 'discount_amount' => 60000]],
            'payments' => [['method' => 'cash', 'amount' => 0.01]],
        ]));

        // ValidationException dari OrderService dipetakan ke 409 (konflik
        // aturan bisnis), bukan 422 — lihat OrderController::store().
        $response->assertStatus(409)->assertJsonPath('errors.items.0', __('orders_payments.discount_exceeds_line_value', ['sku' => $this->variant->sku]));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_level_discount_exceeding_subtotal_is_rejected(): void
    {
        // SEC-H2 — diskon tingkat order (60000) melebihi subtotal
        // (25000 x 2 = 50000), yang sebelumnya bisa membuat total_amount
        // negatif.
        $response = $this->postJson('/api/v1/orders', $this->basePayload([
            'discount_amount' => 60000,
            'payments' => [['method' => 'cash', 'amount' => 0.01]],
        ]));

        $response->assertStatus(409)->assertJsonPath('errors.discount_amount.0', __('orders_payments.discount_exceeds_subtotal'));
        $this->assertDatabaseCount('orders', 0);
    }

    // --- Negative: sesi kasir -----------------------------------------

    public function test_order_rejected_when_session_already_closed(): void
    {
        $this->session->update(['status' => 'closed']);

        $response = $this->postJson('/api/v1/orders', $this->basePayload());

        $response->assertStatus(409);
    }

    // --- Void ---------------------------------------------------------

    public function test_cashier_cannot_void_order(): void
    {
        $order = $this->postJson('/api/v1/orders', $this->basePayload())->json();

        $this->postJson("/api/v1/orders/{$order['id']}/void", ['reason' => 'Salah input'])
            ->assertStatus(403);
    }

    public function test_owner_can_void_order_and_stock_is_restored(): void
    {
        $order = $this->postJson('/api/v1/orders', $this->basePayload())->json();
        $this->assertSame(8, $this->variant->fresh()->current_stock);

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $response = $this->postJson("/api/v1/orders/{$order['id']}/void", ['reason' => 'Salah input']);

        $response->assertOk()->assertJsonPath('status', 'voided');
        $this->assertSame(10, $this->variant->fresh()->current_stock);
    }

    public function test_voiding_an_already_voided_order_is_rejected(): void
    {
        $order = $this->postJson('/api/v1/orders', $this->basePayload())->json();
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $this->postJson("/api/v1/orders/{$order['id']}/void", ['reason' => 'Pertama'])->assertOk();
        $this->postJson("/api/v1/orders/{$order['id']}/void", ['reason' => 'Kedua'])->assertStatus(409);
    }

    // 001-user-store-settings User Story 3 / SC-004 — celah yang
    // ditemukan saat implementasi profil toko: struk sebelumnya hanya
    // menampilkan store_name/store_contact, padahal spec mewajibkan
    // identitas toko LENGKAP (alamat, logo, kontak person) tercantum.
    public function test_receipt_includes_full_store_profile_when_configured(): void
    {
        Setting::updateOrCreate(['key' => 'store_name'], ['value' => 'Toko Merch', 'type' => 'string', 'group' => 'receipt']);
        Setting::updateOrCreate(['key' => 'store_address'], ['value' => 'Jl. Merdeka No. 1, Jakarta', 'type' => 'string', 'group' => 'receipt']);
        Setting::updateOrCreate(['key' => 'store_logo_path'], ['value' => 'store-logo/logo-test.png', 'type' => 'string', 'group' => 'receipt']);
        Setting::updateOrCreate(['key' => 'store_contact_person'], ['value' => 'Budi Santoso', 'type' => 'string', 'group' => 'receipt']);
        Setting::updateOrCreate(['key' => 'store_contact_phone'], ['value' => '0812-3456-7890', 'type' => 'string', 'group' => 'receipt']);
        Setting::updateOrCreate(['key' => 'store_contact_email'], ['value' => 'toko@contoh.com', 'type' => 'string', 'group' => 'receipt']);

        $order = $this->postJson('/api/v1/orders', $this->basePayload())->json();

        $response = $this->getJson("/api/v1/orders/{$order['id']}/receipt");

        $response->assertOk()
            ->assertJsonPath('store_name', 'Toko Merch')
            ->assertJsonPath('store_address', 'Jl. Merdeka No. 1, Jakarta')
            ->assertJsonPath('store_contact_person', 'Budi Santoso')
            ->assertJsonPath('store_contact_phone', '0812-3456-7890')
            ->assertJsonPath('store_contact_email', 'toko@contoh.com');
        $this->assertStringContainsString('store-logo/logo-test.png', $response->json('store_logo_url'));
    }

    public function test_receipt_omits_store_profile_fields_gracefully_when_unconfigured(): void
    {
        $order = $this->postJson('/api/v1/orders', $this->basePayload())->json();

        $response = $this->getJson("/api/v1/orders/{$order['id']}/receipt");

        $response->assertOk()
            ->assertJsonPath('store_address', null)
            ->assertJsonPath('store_logo_url', null)
            ->assertJsonPath('store_contact_person', null);
    }

    // 014-sales-receipt-event-footer (US2) — footer struk kini juga
    // menampilkan info event (lokasi & tanggal), bukan cuma nama.
    public function test_receipt_includes_event_location_and_dates(): void
    {
        $order = $this->postJson('/api/v1/orders', $this->basePayload())->json();

        $response = $this->getJson("/api/v1/orders/{$order['id']}/receipt");

        $event = $this->session->event;

        $response->assertOk()
            ->assertJsonPath('event_location', $event->location)
            ->assertJsonPath('event_start_date', $event->start_date?->toDateString())
            ->assertJsonPath('event_end_date', $event->end_date?->toDateString());
    }
}
