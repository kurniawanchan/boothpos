<?php

namespace Tests\Support;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Order;
use App\Models\Preorder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PreorderService;
use Illuminate\Support\Str;

/**
 * 076-settlement-tab-session-branding — fixture bersama test settlement.
 * Sengaja memakai service nyata (OrderService/PreorderService) supaya angka
 * yang diuji adalah angka yang benar-benar dibentuk aplikasi, bukan baris
 * buatan tangan. Semua data sintetis, tanpa PII asli.
 *
 * Pemanggil memanggil setUpSettlementWorld() dari setUp().
 */
trait BuildsSettlementFixtures
{
    protected User $owner;

    protected User $cashier;

    protected Event $event;

    protected CashierSession $session;

    protected Category $category;

    protected function setUpSettlementWorld(): void
    {
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->event = Event::factory()->create(['status' => 'active']);
        $this->session = CashierSession::factory()->create([
            'event_id' => $this->event->id, 'user_id' => $this->cashier->id, 'status' => 'open',
        ]);
        $this->category = Category::factory()->create();

        $this->actingAs($this->owner, 'sanctum');
    }

    /** @return array{0: Artist, 1: ProductVariant} */
    protected function seller(string $code, float $price = 10000, float $cost = 4000, ?string $name = null): array
    {
        $artist = Artist::factory()->create(['code' => $code, 'name' => $name ?? "Seller {$code}"]);
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $this->category->id]);
        $variant = $product->variants()->create([
            'sku' => $code.'KYAAA0001', 'sell_price' => $price, 'cost_price' => $cost, 'current_stock' => 1000,
        ]);

        return [$artist, $variant];
    }

    /** @param list<array{0: ProductVariant, 1: int}> $lines */
    protected function posSale(array $lines): Order
    {
        $total = collect($lines)->sum(fn ($l) => $l[0]->sell_price * $l[1]);

        return app(OrderService::class)->create([
            'session_id' => $this->session->id,
            'local_ref' => (string) Str::uuid(),
            'items' => collect($lines)->map(fn ($l) => ['variant_id' => $l[0]->id, 'qty' => $l[1]])->all(),
            'payments' => [['method' => 'cash', 'amount' => $total]],
        ], $this->cashier);
    }

    protected function voidOrder(Order $order): void
    {
        app(OrderService::class)->void($order, 'test void', $this->owner);
    }

    /** @param list<array{0: ProductVariant, 1: int}> $lines */
    protected function preorder(array $lines, float $paid, ?Event $event = null): Preorder
    {
        $service = app(PreorderService::class);
        $preorder = $service->create([
            'event_id' => ($event ?? $this->event)->id,
            'customer_id' => Customer::factory()->create()->id,
            'fulfillment' => 'pickup',
            'items' => collect($lines)->map(fn ($l) => ['variant_id' => $l[0]->id, 'qty' => $l[1]])->all(),
        ], $this->owner);

        if ($paid > 0) {
            $service->recordPayment($preorder, [
                'method' => 'cash', 'channel_id' => null, 'purpose' => 'down_payment', 'amount' => $paid,
            ]);
        }

        return $preorder->fresh(['items', 'payments']);
    }

    protected function cancelPreorder(Preorder $preorder): void
    {
        app(PreorderService::class)->transitionStatus($preorder, 'cancelled', 'test cancel', $this->owner);
    }

    /** Buat draft lewat API dan kembalikan id-nya. */
    protected function createDraft(Artist $artist, ?Event $event = null): int
    {
        return (int) $this->postJson('/api/v1/settlements', [
            'event_id' => ($event ?? $this->event)->id,
            'artist_id' => $artist->id,
        ])->assertCreated()->json('data.id');
    }

    /** PNG 1×1 valid — cukup untuk uji unggah tanda tangan / bukti. */
    protected function pngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    protected function pngUpload(string $name = 'file.png'): \Illuminate\Http\UploadedFile
    {
        return \Illuminate\Http\UploadedFile::fake()->createWithContent($name, $this->pngBytes());
    }

    /**
     * Settlement yang sudah FINAL dengan pendapatan POS `$units × $price` dan,
     * opsional, satu potongan. Mengembalikan id.
     */
    protected function finalizedSettlement(string $code = 'AAA', int $units = 3, float $price = 10000, float $deduction = 0): int
    {
        [$artist, $variant] = $this->seller($code, $price);
        $this->posSale([[$variant, $units]]);
        $id = $this->createDraft($artist);

        if ($deduction > 0) {
            $this->postJson("/api/v1/settlements/{$id}/adjustments", [
                'kind' => 'deduction', 'label' => 'Biaya', 'reason' => 'R', 'amount' => (string) $deduction,
            ])->assertCreated();
        }
        $this->postJson("/api/v1/settlements/{$id}/finalize")->assertOk();

        return $id;
    }

    /** @return array<string, mixed> */
    protected function paymentPayload(string $amount, array $overrides = []): array
    {
        return array_merge([
            'client_ref' => (string) \Illuminate\Support\Str::uuid(),
            'amount' => $amount,
            'paid_at' => now()->toDateString(),
            'method' => 'bank_transfer',
            'reference' => 'TRF-001',
        ], $overrides);
    }
}

