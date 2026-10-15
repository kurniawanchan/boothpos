<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Preorder;
use App\Models\PreorderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * 027-preorder-duplicate-split (US3 + US4) — POST /preorders/{id}/split.
 *
 * Invarian yang dikunci (research.md Decisions 4–5, data-model.md): jumlah
 * unit per varian dan subtotal gabungan tak berubah, stok TIDAK disentuh,
 * diskon/ongkir/pengiriman tetap di pesanan asal, split ditolak bila status
 * sudah tertutup atau ada pembayaran, dan semuanya atomik.
 */
class PreorderSplitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    private Artist $artistA;

    private Artist $artistB;

    private Category $category;

    private int $skuSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->user, 'sanctum');

        $this->artistA = Artist::factory()->create();
        $this->artistB = Artist::factory()->create();
        $this->category = Category::factory()->create();
        $this->customer = Customer::factory()->create();
    }

    private function variant(float $price, ?Artist $artist = null): ProductVariant
    {
        $product = Product::factory()->create([
            'artist_id' => ($artist ?? $this->artistA)->id,
            'category_id' => $this->category->id,
            'is_preorder' => true,
        ]);

        return $product->variants()->create([
            'sku' => sprintf('SPLT%04d', ++$this->skuSeq),
            'sell_price' => $price, 'cost_price' => $price / 2, 'current_stock' => 0,
        ]);
    }

    /** @return array{0: array, 1: ProductVariant, 2: ProductVariant, 3: ProductVariant} [preorder payload, A(4×100k, sellerA), B(1×50k, sellerB), C(2×25k, sellerA)] */
    private function threeLineOrder(array $overrides = []): array
    {
        $a = $this->variant(100000, $this->artistA);
        $b = $this->variant(50000, $this->artistB);
        $c = $this->variant(25000, $this->artistA);

        $order = $this->postJson('/api/v1/preorders', array_merge([
            'customer_id' => $this->customer->id, 'fulfillment' => 'pickup',
            'items' => [
                ['variant_id' => $a->id, 'qty' => 4],
                ['variant_id' => $b->id, 'qty' => 1],
                ['variant_id' => $c->id, 'qty' => 2],
            ],
        ], $overrides))->assertCreated()->json();

        return [$order, $a, $b, $c];
    }

    private function itemId(array $order, ProductVariant $variant): int
    {
        return PreorderItem::where('preorder_id', $order['id'])->where('variant_id', $variant->id)->value('id');
    }

    private function split(int $id, array $items)
    {
        return $this->postJson("/api/v1/preorders/{$id}/split", ['mode' => 'items', 'items' => $items]);
    }

    private function unitsByVariant(): array
    {
        return PreorderItem::selectRaw('variant_id, SUM(qty) as units')->groupBy('variant_id')
            ->pluck('units', 'variant_id')->map(fn ($u) => (int) $u)->all();
    }

    // --- US3: pemisahan manual ----------------------------------------------

    public function test_moving_a_whole_line_keeps_the_same_item_row_and_reconciles_the_totals(): void
    {
        [$order, $a, $b, $c] = $this->threeLineOrder(['shipping_cost' => 5000, 'discount' => 10000, 'notes' => 'catatan asli']);
        $bItem = $this->itemId($order, $b);
        $unitsBefore = $this->unitsByVariant();

        $response = $this->split($order['id'], [['item_id' => $bItem, 'qty' => 1]])->assertCreated();

        $new = $response->json('created.0');
        $original = $response->json('original');
        $this->assertCount(1, $response->json('created'));

        // baris yang dipindah adalah BARIS YANG SAMA (id dipertahankan), kini milik pesanan baru
        $this->assertSame($new['id'], PreorderItem::findOrFail($bItem)->preorder_id);
        $this->assertSame(1, PreorderItem::where('preorder_id', $new['id'])->count());
        $this->assertSame(2, PreorderItem::where('preorder_id', $order['id'])->count());

        // 4×100k + 2×25k = 450k tersisa; 1×50k pindah. Ongkir/diskon tetap di asal.
        $this->assertSame('450000.00', $original['subtotal']);
        $this->assertSame('445000.00', $original['total_amount']); // 450k + 5k − 10k
        $this->assertSame('50000.00', $new['subtotal']);
        $this->assertSame('50000.00', $new['total_amount']);
        $this->assertSame(500000.0, (float) $original['subtotal'] + (float) $new['subtotal']);
        $this->assertSame($unitsBefore, $this->unitsByVariant());
    }

    public function test_moving_part_of_a_line_shrinks_the_original_and_clones_the_snapshot(): void
    {
        [$order, $a] = $this->threeLineOrder();
        $aItem = PreorderItem::findOrFail($this->itemId($order, $a));

        $response = $this->split($order['id'], [['item_id' => $aItem->id, 'qty' => 1]])->assertCreated();

        $newId = $response->json('created.0.id');
        $kept = $aItem->fresh();
        $this->assertSame(3, $kept->qty);
        $this->assertEquals(300000, $kept->line_total);
        $this->assertSame($order['id'], $kept->preorder_id);

        $moved = PreorderItem::where('preorder_id', $newId)->firstOrFail();
        $this->assertNotSame($aItem->id, $moved->id);
        $this->assertSame(1, $moved->qty);
        $this->assertEquals(100000, $moved->line_total);
        $this->assertSame($aItem->variant_id, $moved->variant_id);
        $this->assertSame($aItem->artist_id, $moved->artist_id);
        $this->assertSame($aItem->sku_snapshot, $moved->sku_snapshot);
        $this->assertSame($aItem->name_snapshot, $moved->name_snapshot);
        $this->assertEquals($aItem->cost_price, $moved->cost_price);
        $this->assertEquals($aItem->sell_price, $moved->sell_price);
    }

    public function test_the_new_order_copies_the_context_and_the_original_keeps_what_stays(): void
    {
        $event = Event::factory()->create(['start_date' => '2026-10-12', 'end_date' => '2026-10-13']);
        [$order, , $b] = $this->threeLineOrder([
            'event_id' => $event->id, 'pickup_day' => '2026-10-13', 'expected_date' => '2026-11-01',
            'shipping_cost' => 5000, 'discount' => 10000, 'notes' => 'catatan asli',
        ]);
        Preorder::whereKey($order['id'])->update(['dispatch_status' => 'invoice_sent', 'invoice_sent_at' => now()]);
        $other = User::factory()->create(['role' => 'admin']);
        $this->actingAs($other, 'sanctum');

        $newId = $this->split($order['id'], [['item_id' => $this->itemId($order, $b), 'qty' => 1]])
            ->assertCreated()->json('created.0.id');

        $new = Preorder::findOrFail($newId);
        $this->assertSame($this->customer->id, $new->customer_id);
        $this->assertSame($event->id, $new->event_id);
        $this->assertSame('pickup', $new->fulfillment);
        $this->assertSame('2026-10-13', $new->pickup_day->toDateString());
        $this->assertSame('2026-11-01', $new->expected_date->toDateString());
        $this->assertSame('ordered', $new->status);
        $this->assertEquals(0, $new->shipping_cost);
        $this->assertEquals(0, $new->discount);
        $this->assertNull($new->notes);
        $this->assertSame('pending', $new->dispatch_status);
        $this->assertNull($new->invoice_sent_at);
        $this->assertEquals(0, $new->paid_amount);
        $this->assertSame($other->id, $new->user_id);
        $this->assertSame('split', $new->source_type);
        $this->assertSame($order['id'], $new->source_preorder_id);
        $this->assertSame($order['preorder_number'], $new->source_preorder_number);
        $this->assertNotSame($order['preorder_number'], $new->preorder_number);

        $original = Preorder::findOrFail($order['id']);
        $this->assertEquals(5000, $original->shipping_cost);
        $this->assertEquals(10000, $original->discount);
        $this->assertSame('catatan asli', $original->notes);
        $this->assertSame('invoice_sent', $original->dispatch_status);
        $this->assertNull($original->source_type);
    }

    public function test_the_original_lists_its_split_children_and_the_new_order_points_back(): void
    {
        [$order, , $b] = $this->threeLineOrder();

        $response = $this->split($order['id'], [['item_id' => $this->itemId($order, $b), 'qty' => 1]])->assertCreated();

        $newId = $response->json('created.0.id');
        $response->assertJsonPath('original.split_children.0.id', $newId)
            ->assertJsonPath('created.0.source.type', 'split')
            ->assertJsonPath('created.0.source.preorder_number', $order['preorder_number']);
        $this->getJson("/api/v1/preorders/{$order['id']}")->assertJsonPath('split_children.0.id', $newId);
    }

    public function test_the_shipment_stays_with_the_original(): void
    {
        [$order, , $b] = $this->threeLineOrder(['fulfillment' => 'courier', 'courier_name' => 'JNE', 'shipping_cost' => 10000]);
        Shipment::create([
            'preorder_id' => $order['id'], 'courier_name' => 'JNE', 'recipient_name' => 'Budi',
            'recipient_phone' => '0800', 'address_line' => 'Jl. Contoh 1',
        ]);

        $newId = $this->split($order['id'], [['item_id' => $this->itemId($order, $b), 'qty' => 1]])
            ->assertCreated()->json('created.0.id');

        $this->assertDatabaseHas('shipments', ['preorder_id' => $order['id']]);
        $this->assertDatabaseMissing('shipments', ['preorder_id' => $newId]);
        $this->assertSame('courier', Preorder::findOrFail($newId)->fulfillment);
        $this->assertSame('JNE', Preorder::findOrFail($newId)->courier_name);
    }

    public function test_a_second_split_cannot_move_units_that_already_left(): void
    {
        [$order, , $b] = $this->threeLineOrder();
        $bItem = $this->itemId($order, $b);
        $this->split($order['id'], [['item_id' => $bItem, 'qty' => 1]])->assertCreated();

        $this->split($order['id'], [['item_id' => $bItem, 'qty' => 1]])->assertStatus(422);
    }

    public function test_the_same_item_listed_twice_is_added_up_before_validating(): void
    {
        [$order, $a] = $this->threeLineOrder();
        $aItem = $this->itemId($order, $a);

        // 3 + 2 = 5 > 4 unit pada baris itu
        $this->split($order['id'], [['item_id' => $aItem, 'qty' => 3], ['item_id' => $aItem, 'qty' => 2]])
            ->assertStatus(422);
        $this->assertSame(4, PreorderItem::findOrFail($aItem)->qty);
    }

    public function test_the_list_flags_rows_that_already_have_a_payment(): void
    {
        [$paid] = $this->threeLineOrder();
        [$unpaid] = $this->threeLineOrder();
        $this->postJson("/api/v1/preorders/{$paid['id']}/payments", [
            'method' => 'cash', 'amount' => 1000, 'purpose' => 'down_payment',
        ])->assertCreated();

        $rows = collect($this->getJson('/api/v1/preorders')->assertOk()->json('data'))->keyBy('id');

        $this->assertTrue($rows[$paid['id']]['has_payments']);
        $this->assertFalse($rows[$unpaid['id']]['has_payments']);
    }

    // --- US3: penjagaan -----------------------------------------------------

    public function test_a_handed_over_or_cancelled_pre_order_cannot_be_split(): void
    {
        [$order, , $b] = $this->threeLineOrder();
        $bItem = $this->itemId($order, $b);

        foreach (['handed_over', 'cancelled'] as $status) {
            Preorder::whereKey($order['id'])->update(['status' => $status]);
            $this->split($order['id'], [['item_id' => $bItem, 'qty' => 1]])->assertStatus(409);
        }
        $this->assertSame(1, Preorder::count());
    }

    public function test_any_recorded_payment_blocks_the_split_even_a_small_deposit(): void
    {
        [$order, , $b] = $this->threeLineOrder();
        $this->postJson("/api/v1/preorders/{$order['id']}/payments", [
            'method' => 'cash', 'amount' => 1000, 'purpose' => 'down_payment',
        ])->assertCreated();

        $response = $this->split($order['id'], [['item_id' => $this->itemId($order, $b), 'qty' => 1]]);

        $response->assertStatus(409);
        $this->assertSame(1, Preorder::count());
        $this->assertSame($order['id'], PreorderItem::findOrFail($this->itemId($order, $b))->preorder_id);
    }

    public function test_the_split_is_refused_when_the_discount_would_exceed_what_remains(): void
    {
        $a = $this->variant(100000);
        $b = $this->variant(20000);
        $order = $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id, 'fulfillment' => 'pickup', 'discount' => 50000,
            'items' => [['variant_id' => $a->id, 'qty' => 1], ['variant_id' => $b->id, 'qty' => 1]],
        ])->assertCreated()->json();
        $aItem = $this->itemId($order, $a);

        // memindahkan A menyisakan hanya 20.000 di pesanan asal, di bawah diskon 50.000
        $this->split($order['id'], [['item_id' => $aItem, 'qty' => 1]])->assertStatus(409);

        $this->assertSame(1, Preorder::count());
        $this->assertSame($order['id'], PreorderItem::findOrFail($aItem)->preorder_id);
        $this->assertEquals(120000, Preorder::findOrFail($order['id'])->subtotal);
    }

    public function test_an_item_from_another_order_is_rejected(): void
    {
        [$order] = $this->threeLineOrder();
        [$other, $x] = $this->threeLineOrder();
        $foreign = $this->itemId($other, $x);

        $this->split($order['id'], [['item_id' => $foreign, 'qty' => 1]])->assertStatus(422);
        $this->assertSame($other['id'], PreorderItem::findOrFail($foreign)->preorder_id);
    }

    public function test_a_quantity_above_the_line_is_rejected(): void
    {
        [$order, , $b] = $this->threeLineOrder();

        $this->split($order['id'], [['item_id' => $this->itemId($order, $b), 'qty' => 2]])->assertStatus(422);
    }

    public function test_something_must_move_and_something_must_stay(): void
    {
        [$order, $a, $b, $c] = $this->threeLineOrder();

        // semua unit pindah → tak ada yang tersisa
        $this->split($order['id'], [
            ['item_id' => $this->itemId($order, $a), 'qty' => 4],
            ['item_id' => $this->itemId($order, $b), 'qty' => 1],
            ['item_id' => $this->itemId($order, $c), 'qty' => 2],
        ])->assertStatus(422)->assertJsonPath('errors.items.0', __('preorders.split_must_move_and_keep'));

        // tidak ada yang dipindah / bentuk salah
        $this->postJson("/api/v1/preorders/{$order['id']}/split", ['mode' => 'items', 'items' => []])->assertStatus(422);
        $this->postJson("/api/v1/preorders/{$order['id']}/split", ['mode' => 'items'])->assertStatus(422);
        $this->postJson("/api/v1/preorders/{$order['id']}/split", ['mode' => 'nonsense'])->assertStatus(422);
        $this->split($order['id'], [['item_id' => $this->itemId($order, $a), 'qty' => 0]])->assertStatus(422);

        $this->assertSame(1, Preorder::count());
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        [$order, , $b] = $this->threeLineOrder();
        $this->app['auth']->forgetGuards();

        $this->split($order['id'], [['item_id' => $this->itemId($order, $b), 'qty' => 1]])->assertStatus(401);
    }

    // --- US3: invarian ------------------------------------------------------

    public function test_splitting_an_arrived_order_writes_no_stock_movement_and_keeps_stock(): void
    {
        [$order, $a, $b] = $this->threeLineOrder();
        $this->patchJson("/api/v1/preorders/{$order['id']}/status", ['status' => 'dp_paid'])->assertOk();
        $this->patchJson("/api/v1/preorders/{$order['id']}/status", ['status' => 'arrived'])->assertOk();
        $movementsBefore = \App\Models\StockMovement::count();
        $stockBefore = ProductVariant::orderBy('id')->pluck('current_stock', 'id')->all();
        $this->assertGreaterThan(0, $movementsBefore);

        $response = $this->split($order['id'], [
            ['item_id' => $this->itemId($order, $a), 'qty' => 1],
            ['item_id' => $this->itemId($order, $b), 'qty' => 1],
        ])->assertCreated();

        $this->assertSame($movementsBefore, \App\Models\StockMovement::count());
        $this->assertEquals($stockBefore, ProductVariant::orderBy('id')->pluck('current_stock', 'id')->all());
        // pesanan baru mewarisi status "arrived" (barangnya sudah tiba)
        $this->assertSame('arrived', $response->json('created.0.status'));
    }

    public function test_a_failure_in_the_middle_leaves_everything_untouched(): void
    {
        [$order, $a, $b] = $this->threeLineOrder();
        $aItem = PreorderItem::findOrFail($this->itemId($order, $a));
        $bItem = $this->itemId($order, $b);
        $before = Preorder::with('items')->findOrFail($order['id'])->toArray();

        // Log aktivitas ditulis PALING AKHIR di dalam transaksi — gagal di sana
        // harus membatalkan pesanan baru, pemindahan baris, dan pengecilan qty.
        $this->mock(ActivityLogger::class, fn ($mock) => $mock->shouldReceive('log')->andThrow(new \RuntimeException('log gagal')));

        $this->split($order['id'], [
            ['item_id' => $aItem->id, 'qty' => 1],
            ['item_id' => $bItem, 'qty' => 1],
        ])->assertStatus(500);

        $this->assertSame(1, Preorder::count());
        $this->assertEquals($before, Preorder::with('items')->findOrFail($order['id'])->toArray());
    }

    public function test_splitting_sends_no_notification_and_writes_one_activity_row(): void
    {
        Mail::fake();
        [$order, , $b] = $this->threeLineOrder();

        $newNumber = $this->split($order['id'], [['item_id' => $this->itemId($order, $b), 'qty' => 1]])
            ->assertCreated()->json('created.0.preorder_number');

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->assertDatabaseCount('preorder_notifications', 0);
        $this->assertDatabaseCount('activity_logs', 1);
        $log = \App\Models\ActivityLog::firstOrFail();
        $this->assertSame('split', $log->action);
        $this->assertSame('Preorder', $log->entity_type);
        $this->assertSame($order['id'], $log->entity_id);
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertStringContainsString($newNumber, (string) $log->description);
        $this->assertNotEmpty($log->new_values['moved'] ?? null);
    }

    public function test_a_split_stays_in_the_active_data_mode(): void
    {
        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'demo', 'type' => 'string', 'group' => 'system']);
        [$customer, $variantA, $variantB] = ModeGate::runAs('demo', fn () => [
            Customer::factory()->create(), $this->variant(10000), $this->variant(20000),
        ]);
        $order = $this->postJson('/api/v1/preorders', [
            'customer_id' => $customer->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $variantA->id, 'qty' => 1], ['variant_id' => $variantB->id, 'qty' => 1]],
        ])->assertCreated()->json();

        $aItem = $this->itemId($order, $variantA);
        $newId = $this->split($order['id'], [['item_id' => $this->itemId($order, $variantB), 'qty' => 1]])
            ->assertCreated()->json('created.0.id');

        $new = Preorder::withoutGlobalScopes()->with('items')->findOrFail($newId);
        $this->assertSame('demo', $new->data_mode);
        $this->assertSame('demo', $new->items->first()->data_mode);
        $this->assertNotSame($order['preorder_number'], $new->preorder_number);

        // dari mode lain, pesanan itu tak terlihat sama sekali
        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'live', 'type' => 'string', 'group' => 'system']);
        $this->split($order['id'], [['item_id' => $aItem, 'qty' => 1]])->assertStatus(404);
    }

    public function test_money_and_stock_totals_are_identical_before_and_after_a_split(): void
    {
        [$order, $a, $b] = $this->threeLineOrder(['shipping_cost' => 5000, 'discount' => 10000]);
        $before = $this->getJson('/api/v1/preorders/summary')->assertOk()->json();
        $salesBefore = $this->getJson('/api/v1/reports/sales')->assertOk()->json('totals');

        $this->split($order['id'], [
            ['item_id' => $this->itemId($order, $a), 'qty' => 2],
            ['item_id' => $this->itemId($order, $b), 'qty' => 1],
        ])->assertCreated();

        $after = $this->getJson('/api/v1/preorders/summary')->assertOk()->json();
        $this->assertSame(1, $before['transaction_count']);
        $this->assertSame(2, $after['transaction_count']);
        $this->assertSame($before['grand_total'], $after['grand_total']);
        $this->assertSame($before['total_outstanding'], $after['total_outstanding']);
        $this->assertEquals($salesBefore, $this->getJson('/api/v1/reports/sales')->json('totals'));
    }

    // --- US4: pisah per penjual ---------------------------------------------

    private function splitBySeller(int $id)
    {
        return $this->postJson("/api/v1/preorders/{$id}/split", ['mode' => 'by_seller']);
    }

    public function test_by_seller_keeps_the_first_lines_seller_and_moves_every_other_seller_out(): void
    {
        // A (penjual A, baris pertama), B (penjual B), C (penjual A)
        [$order, $a, $b, $c] = $this->threeLineOrder(['shipping_cost' => 5000]);
        $bItem = $this->itemId($order, $b);

        $response = $this->splitBySeller($order['id'])->assertCreated();

        $this->assertCount(1, $response->json('created'));
        $newId = $response->json('created.0.id');
        $this->assertSame([$this->itemId($order, $a), $this->itemId($order, $c)],
            PreorderItem::where('preorder_id', $order['id'])->orderBy('id')->pluck('id')->all());
        $this->assertSame([$bItem], PreorderItem::where('preorder_id', $newId)->pluck('id')->all()); // baris yang sama
        $this->assertSame('split', $response->json('created.0.source.type'));
        $this->assertSame('500000.00', number_format(
            (float) $response->json('original.subtotal') + (float) $response->json('created.0.subtotal'), 2, '.', ''));
    }

    public function test_by_seller_creates_one_order_per_extra_seller(): void
    {
        $artistC = Artist::factory()->create();
        $a = $this->variant(10000, $this->artistA);
        $b = $this->variant(20000, $this->artistB);
        $c = $this->variant(30000, $artistC);
        $d = $this->variant(40000, $this->artistB);
        $order = $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id, 'fulfillment' => 'pickup',
            'items' => [
                ['variant_id' => $a->id, 'qty' => 1], ['variant_id' => $b->id, 'qty' => 2],
                ['variant_id' => $c->id, 'qty' => 1], ['variant_id' => $d->id, 'qty' => 1],
            ],
        ])->assertCreated()->json();

        $response = $this->splitBySeller($order['id'])->assertCreated();

        $created = $response->json('created');
        $this->assertCount(2, $created);
        $this->assertSame(1, PreorderItem::where('preorder_id', $order['id'])->count());      // hanya penjual A
        $this->assertSame(2, PreorderItem::where('preorder_id', $created[0]['id'])->count()); // penjual B (b + d), utuh
        $this->assertSame(1, PreorderItem::where('preorder_id', $created[1]['id'])->count()); // penjual C
        // semua unit utuh: qty baris B tidak dipecah
        $this->assertSame(2, PreorderItem::where('preorder_id', $created[0]['id'])->where('variant_id', $b->id)->value('qty'));

        $log = \App\Models\ActivityLog::where('action', 'split')->firstOrFail();
        $this->assertSame($order['id'], $log->entity_id);
        foreach ($created as $row) {
            $this->assertStringContainsString($row['preorder_number'], (string) $log->description);
        }
        $this->assertSame(1, \App\Models\ActivityLog::count());
    }

    public function test_by_seller_on_a_single_seller_order_is_refused(): void
    {
        $a = $this->variant(10000, $this->artistA);
        $b = $this->variant(20000, $this->artistA);
        $order = $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $a->id, 'qty' => 1], ['variant_id' => $b->id, 'qty' => 1]],
        ])->assertCreated()->json();

        $this->splitBySeller($order['id'])->assertStatus(422)->assertJsonValidationErrors('mode');
        $this->assertSame(1, Preorder::count());
    }

    public function test_by_seller_obeys_the_same_guards_as_a_manual_split(): void
    {
        // pembayaran → 409
        [$paid] = $this->threeLineOrder();
        $this->postJson("/api/v1/preorders/{$paid['id']}/payments", [
            'method' => 'cash', 'amount' => 1000, 'purpose' => 'down_payment',
        ])->assertCreated();
        $this->splitBySeller($paid['id'])->assertStatus(409);

        // status tertutup → 409
        [$closed] = $this->threeLineOrder();
        Preorder::whereKey($closed['id'])->update(['status' => 'cancelled']);
        $this->splitBySeller($closed['id'])->assertStatus(409);

        // diskon melebihi sisa penjual pertama → 409 dan tidak ada yang berubah
        $a = $this->variant(10000, $this->artistA);
        $b = $this->variant(90000, $this->artistB);
        $discounted = $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id, 'fulfillment' => 'pickup', 'discount' => 50000,
            'items' => [['variant_id' => $a->id, 'qty' => 1], ['variant_id' => $b->id, 'qty' => 1]],
        ])->assertCreated()->json();
        $before = Preorder::count();
        $this->splitBySeller($discounted['id'])->assertStatus(409); // penjual A hanya 10.000 < diskon 50.000
        $this->assertSame($before, Preorder::count());
        $this->assertSame(2, PreorderItem::where('preorder_id', $discounted['id'])->count());
    }

    public function test_by_seller_on_an_arrived_order_writes_no_stock_movement(): void
    {
        [$order] = $this->threeLineOrder();
        $this->patchJson("/api/v1/preorders/{$order['id']}/status", ['status' => 'dp_paid'])->assertOk();
        $this->patchJson("/api/v1/preorders/{$order['id']}/status", ['status' => 'arrived'])->assertOk();
        $movements = \App\Models\StockMovement::count();

        $response = $this->splitBySeller($order['id'])->assertCreated();

        $this->assertSame($movements, \App\Models\StockMovement::count());
        $this->assertSame('arrived', $response->json('created.0.status'));
    }
}
