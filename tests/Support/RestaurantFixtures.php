<?php

namespace Tests\Support;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Event;
use App\Models\KitchenTicket;
use App\Models\KitchenTicketItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\Product;
use App\Models\Station;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Fixture bersama untuk seluruh test fitur restoran (028). Satu tempat
 * supaya test tab/dapur/modifier tidak masing-masing menyalin rangkaian
 * "event -> sesi -> artis -> kategori -> produk -> varian" yang sama.
 *
 * Sengaja memakai Order::create() langsung untuk tab terbuka: pada fase
 * fondasi (sebelum TabService ada) test pagar-dampak tetap bisa
 * membuktikan bahwa order 'open' tidak pernah terhitung sebagai uang.
 */
trait RestaurantFixtures
{
    private static int $fixtureSeq = 0;

    protected function makeShift(User $user, array $overrides = []): CashierSession
    {
        $event = Event::factory()->create(['status' => 'active']);

        return CashierSession::factory()->create(array_merge([
            'event_id' => $event->id, 'user_id' => $user->id, 'status' => 'open',
        ], $overrides));
    }

    /**
     * @return ProductVariant varian aktif dengan stok & harga yang diminta
     */
    protected function makeVariant(int $stock = 10, float $price = 25000, array $variantOverrides = []): ProductVariant
    {
        self::$fixtureSeq++;
        $artist = Artist::first() ?? Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);

        return $product->variants()->create(array_merge([
            'sku' => sprintf('RST%09d', self::$fixtureSeq),
            'variant_name' => 'Standard',
            'sell_price' => $price,
            'cost_price' => $price / 2,
            'current_stock' => $stock,
        ], $variantOverrides));
    }

    /**
     * Tab terbuka mentah (tanpa melewati service) — status 'open', belum
     * ada pembayaran. $overrides bisa menimpa kolom apa pun.
     */
    protected function makeOpenOrder(CashierSession $session, User $cashier, array $overrides = []): Order
    {
        self::$fixtureSeq++;

        return Order::create(array_merge([
            'order_number' => sprintf('TRX-TEST-%06d', self::$fixtureSeq),
            'event_id' => $session->event_id,
            'session_id' => $session->id,
            'user_id' => $cashier->id,
            'channel' => 'offline',
            'subtotal' => 0, 'discount_amount' => 0, 'total_amount' => 0,
            'total_cost' => 0, 'paid_amount' => 0, 'change_amount' => 0,
            'status' => 'open',
        ], $overrides));
    }

    protected function addLineTo(Order $order, ProductVariant $variant, int $qty = 1): OrderItem
    {
        $variant->loadMissing('product');

        return $order->items()->create([
            'variant_id' => $variant->id,
            'artist_id' => $variant->product->artist_id,
            'sku_snapshot' => $variant->sku,
            'name_snapshot' => $variant->product->name.' — '.$variant->variant_name,
            'qty' => $qty,
            'cost_price' => $variant->cost_price,
            'sell_price' => $variant->sell_price,
            'discount_amount' => 0,
            'line_total' => (float) $variant->sell_price * $qty,
        ]);
    }

    protected function addCashPaymentTo(Order $order, float $amount): Payment
    {
        return Payment::create([
            'order_id' => $order->id,
            'method' => 'cash',
            'purpose' => 'full',
            'amount' => $amount,
            'verification' => 'verified',
            'paid_at' => now(),
        ]);
    }

    protected function newLocalRef(): string
    {
        return (string) Str::uuid();
    }


    // --- dapur / stasiun ------------------------------------------------------

    protected function makeStation(string $name = 'Dapur', array $over = []): Station
    {
        return Station::create(array_merge(['name' => $name], $over));
    }

    /**
     * Item menu dengan stasiun pada kategori dan/atau pada produknya sendiri
     * (stasiun produk menang atas stasiun kategori).
     */
    protected function makeMenuItem(?Station $categoryStation = null, ?Station $productStation = null, int $stock = 50, float $price = 20000, array $variantOverrides = []): ProductVariant
    {
        self::$fixtureSeq++;
        $artist = Artist::first() ?? Artist::factory()->create();
        $category = Category::factory()->create(['station_id' => $categoryStation?->id]);
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'station_id' => $productStation?->id]);

        return $product->variants()->create(array_merge([
            'sku' => sprintf('RST%09d', self::$fixtureSeq), 'variant_name' => 'Standard',
            'sell_price' => $price, 'cost_price' => $price / 2, 'current_stock' => $stock,
        ], $variantOverrides));
    }

    /** Item tiket dapur mentah (tanpa melewati KitchenService) — untuk test status/feed. */
    protected function makeTicketItem(array $over = [], ?Station $station = null, ?Order $order = null, array $ticketOver = []): KitchenTicketItem
    {
        $user = User::first() ?? User::factory()->create();
        $order ??= $this->makeOpenOrder($this->makeShift($user), $user);
        $ticket = KitchenTicket::create(array_merge([
            'order_id' => $order->id, 'station_id' => $station?->id, 'round_no' => 1,
            'sent_by' => $user->id, 'sent_at' => now(),
        ], $ticketOver));

        return $ticket->items()->create(array_merge(['name_snapshot' => 'Nasi Goreng', 'qty' => 1, 'status' => 'new'], $over));
    }

    // --- helper HTTP untuk test tab (pemanggil sudah actingAs) -------------

    protected function apiOpenTab(int $sessionId, ?int $tableId = null, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/tabs', array_filter(array_merge([
            'session_id' => $sessionId, 'table_id' => $tableId,
        ], $extra), fn ($v) => $v !== null));
    }

    protected function apiAddLine(int $tabId, int $variantId, int $qty = 1, array $extra = []): TestResponse
    {
        return $this->postJson("/api/v1/tabs/{$tabId}/items", array_merge(['variant_id' => $variantId, 'qty' => $qty], $extra));
    }

    protected function apiPay(int $tabId, array $payments, ?string $clientRef = null, array $extra = []): TestResponse
    {
        return $this->postJson("/api/v1/tabs/{$tabId}/payments", array_merge([
            'client_ref' => $clientRef ?? $this->newLocalRef(), 'payments' => $payments,
        ], $extra));
    }

    protected function fakeProofToken(): string
    {
        $token = (string) Str::uuid();
        PaymentProof::create(['proof_token' => $token, 'file_path' => 'test/fake.jpg', 'original_name' => 'fake.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 100, 'created_at' => now()]);

        return $token;
    }
}
