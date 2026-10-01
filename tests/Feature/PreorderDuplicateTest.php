<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Preorder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * 027-preorder-duplicate-split (US1 + US2) — POST /preorders/duplicate.
 *
 * Aturan yang dikunci di sini (spec.md / research.md Decisions 1–3):
 * salinan dibangun di atas PreorderService::create() (harga SAAT INI, mulai
 * "ordered", tanpa pembayaran/pengiriman), sumber tidak berubah, dan tiap
 * pre-order diproses TERPISAH — satu yang gagal tidak menggagalkan yang lain.
 */
class PreorderDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    private Artist $artist;

    private Category $category;

    private int $skuSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->user, 'sanctum');

        $this->artist = Artist::factory()->create();
        $this->category = Category::factory()->create();
        $this->customer = Customer::factory()->create();
    }

    private function variant(float $price = 100000, ?Artist $artist = null, array $overrides = []): ProductVariant
    {
        $product = Product::factory()->create([
            'artist_id' => ($artist ?? $this->artist)->id,
            'category_id' => $this->category->id,
            'is_preorder' => true,
        ]);

        return $product->variants()->create(array_merge([
            'sku' => sprintf('DUPL%04d', ++$this->skuSeq),
            'sell_price' => $price, 'cost_price' => $price / 2, 'current_stock' => 0,
        ], $overrides));
    }

    private function createPreorder(array $overrides = [], ?array $items = null): array
    {
        $items ??= [['variant_id' => $this->variant()->id, 'qty' => 1]];

        return $this->postJson('/api/v1/preorders', array_merge([
            'customer_id' => $this->customer->id, 'fulfillment' => 'pickup', 'items' => $items,
        ], $overrides))->assertCreated()->json();
    }

    private function duplicate(array $ids)
    {
        return $this->postJson('/api/v1/preorders/duplicate', ['preorder_ids' => $ids]);
    }

    // --- US1: satu pre-order ------------------------------------------------

    public function test_a_duplicate_copies_the_content_of_the_source(): void
    {
        $event = Event::factory()->create(['start_date' => '2026-10-12', 'end_date' => '2026-10-13']);
        $a = $this->variant(100000);
        $b = $this->variant(25000);
        $source = $this->createPreorder([
            'event_id' => $event->id, 'pickup_day' => '2026-10-13', 'expected_date' => '2026-11-01',
            'shipping_cost' => 5000, 'discount' => 2000, 'notes' => 'Titip salam',
        ], [['variant_id' => $a->id, 'qty' => 2], ['variant_id' => $b->id, 'qty' => 3]]);

        $response = $this->duplicate([$source['id']])->assertOk();

        $result = $response->json('data.0');
        $this->assertSame('created', $result['status']);
        $this->assertSame($source['id'], $result['source_id']);
        $this->assertSame($source['preorder_number'], $result['source_number']);

        $copy = Preorder::with('items')->findOrFail($result['preorder']['id']);
        $this->assertNotSame($source['preorder_number'], $copy->preorder_number);
        $this->assertSame($this->customer->id, $copy->customer_id);
        $this->assertSame($event->id, $copy->event_id);
        $this->assertSame('pickup', $copy->fulfillment);
        $this->assertSame('2026-10-13', $copy->pickup_day->toDateString());
        $this->assertSame('2026-11-01', $copy->expected_date->toDateString());
        $this->assertSame('Titip salam', $copy->notes);
        $this->assertEquals(5000, $copy->shipping_cost);
        $this->assertEquals(2000, $copy->discount);
        // 2×100.000 + 3×25.000 = 275.000; +5.000 ongkir −2.000 diskon = 278.000
        $this->assertEquals(275000, $copy->subtotal);
        $this->assertEquals(278000, $copy->total_amount);
        $this->assertEqualsCanonicalizing(
            [[$a->id, 2], [$b->id, 3]],
            $copy->items->map(fn ($i) => [$i->variant_id, $i->qty])->all(),
        );
    }

    public function test_a_mail_order_duplicate_keeps_the_courier_preference(): void
    {
        $source = $this->createPreorder(['fulfillment' => 'courier', 'courier_name' => 'J&T', 'shipping_cost' => 15000]);

        $copyId = $this->duplicate([$source['id']])->assertOk()->json('data.0.preorder.id');

        $copy = Preorder::findOrFail($copyId);
        $this->assertSame('courier', $copy->fulfillment);
        $this->assertSame('J&T', $copy->courier_name);
        $this->assertNull($copy->pickup_day);
    }

    public function test_a_duplicate_starts_fresh_with_nothing_carried_over(): void
    {
        $source = $this->createPreorder(['fulfillment' => 'courier', 'courier_name' => 'JNE', 'shipping_cost' => 10000]);
        $this->postJson("/api/v1/preorders/{$source['id']}/payments", [
            'method' => 'cash', 'amount' => 20000, 'purpose' => 'down_payment',
        ])->assertCreated();
        $this->patchJson("/api/v1/preorders/{$source['id']}/dispatch-status", ['dispatch_status' => 'invoice_sent'])->assertOk();
        Shipment::create([
            'preorder_id' => $source['id'], 'courier_name' => 'JNE', 'recipient_name' => 'Budi',
            'recipient_phone' => '0800', 'address_line' => 'Jl. Contoh 1',
        ]);
        $this->assertSame('dp_paid', Preorder::findOrFail($source['id'])->status);

        $copyId = $this->duplicate([$source['id']])->assertOk()->json('data.0.preorder.id');

        $copy = Preorder::findOrFail($copyId);
        $this->assertSame('ordered', $copy->status);
        $this->assertEquals(0, $copy->paid_amount);
        $this->assertSame('pending', $copy->dispatch_status);
        $this->assertNull($copy->invoice_sent_at);
        $this->assertNull($copy->shipping_at);
        $this->assertNull($copy->cancel_reason);
        $this->assertDatabaseMissing('payments', ['preorder_id' => $copyId]);
        $this->assertDatabaseMissing('shipments', ['preorder_id' => $copyId]);
        $this->assertSame($this->user->id, $copy->user_id);
    }

    public function test_a_duplicate_is_priced_at_the_current_variant_price_not_the_recorded_one(): void
    {
        $variant = $this->variant(100000);
        $source = $this->createPreorder([], [['variant_id' => $variant->id, 'qty' => 2]]);
        $variant->update(['sell_price' => 120000]);

        $copyId = $this->duplicate([$source['id']])->assertOk()->json('data.0.preorder.id');

        $copy = Preorder::with('items')->findOrFail($copyId);
        $this->assertEquals(240000, $copy->subtotal);
        $this->assertEquals(120000, $copy->items->first()->sell_price);
        // sumber tetap memakai harga lamanya
        $this->assertEquals(200000, Preorder::findOrFail($source['id'])->subtotal);
    }

    public function test_the_source_is_left_completely_unchanged(): void
    {
        $source = $this->createPreorder(['discount' => 1000, 'notes' => 'asli']);
        $before = Preorder::with('items', 'payments')->findOrFail($source['id'])->toArray();

        $this->duplicate([$source['id']])->assertOk();

        $this->assertEquals($before, Preorder::with('items', 'payments')->findOrFail($source['id'])->toArray());
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_cancelled_and_handed_over_pre_orders_can_be_duplicated_too(): void
    {
        $cancelled = $this->createPreorder();
        $handedOver = $this->createPreorder();
        Preorder::whereKey($cancelled['id'])->update(['status' => 'cancelled', 'cancel_reason' => 'batal']);
        Preorder::whereKey($handedOver['id'])->update(['status' => 'handed_over']);

        $data = $this->duplicate([$cancelled['id'], $handedOver['id']])->assertOk()->json('data');

        foreach ($data as $row) {
            $this->assertSame('created', $row['status']);
            $this->assertSame('ordered', $row['preorder']['status']);
        }
    }

    public function test_the_copy_records_where_it_came_from(): void
    {
        $source = $this->createPreorder();

        $copy = $this->duplicate([$source['id']])->assertOk()->json('data.0.preorder');

        $this->assertSame('duplicate', $copy['source']['type']);
        $this->assertSame($source['id'], $copy['source']['preorder_id']);
        $this->assertSame($source['preorder_number'], $copy['source']['preorder_number']);
        $this->assertSame(
            $source['preorder_number'],
            $this->getJson("/api/v1/preorders/{$copy['id']}")->assertOk()->json('source.preorder_number'),
        );
        // sumber sendiri bukan salinan
        $this->assertNull($this->getJson("/api/v1/preorders/{$source['id']}")->json('source'));
    }

    public function test_duplicating_sends_no_notification(): void
    {
        Mail::fake();
        $source = $this->createPreorder();

        $this->duplicate([$source['id']])->assertOk();

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->assertDatabaseCount('preorder_notifications', 0);
    }

    public function test_a_duplicate_writes_one_activity_log_row(): void
    {
        $source = $this->createPreorder();

        $copyId = $this->duplicate([$source['id']])->assertOk()->json('data.0.preorder.id');

        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'duplicated', 'entity_type' => 'Preorder',
            'entity_id' => $copyId, 'user_id' => $this->user->id,
        ]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/preorders/duplicate', ['preorder_ids' => [1]])->assertStatus(401);
    }

    public function test_a_copy_is_stamped_with_the_active_data_mode_and_numbers_stay_unique_across_modes(): void
    {
        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'demo', 'type' => 'string', 'group' => 'system']);
        $demoCustomer = ModeGate::runAs('demo', fn () => Customer::factory()->create());
        $demoVariant = ModeGate::runAs('demo', fn () => $this->variant());
        $source = $this->postJson('/api/v1/preorders', [
            'customer_id' => $demoCustomer->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $demoVariant->id, 'qty' => 1]],
        ])->assertCreated()->json();
        $this->assertSame('demo', Preorder::withoutGlobalScopes()->findOrFail($source['id'])->data_mode);

        $copyId = $this->duplicate([$source['id']])->assertOk()->json('data.0.preorder.id');

        $copy = Preorder::withoutGlobalScopes()->with('items')->findOrFail($copyId);
        $this->assertSame('demo', $copy->data_mode);
        $this->assertSame('demo', $copy->items->first()->data_mode);
        $this->assertNotSame($source['preorder_number'], $copy->preorder_number);
    }

    // --- US1: kasus tepi ----------------------------------------------------

    public function test_a_deleted_event_is_dropped_from_the_copy_together_with_the_pickup_day(): void
    {
        $event = Event::factory()->create(['start_date' => '2026-10-12', 'end_date' => '2026-10-13']);
        $source = $this->createPreorder(['event_id' => $event->id, 'pickup_day' => '2026-10-12']);
        $event->delete();

        $copyId = $this->duplicate([$source['id']])->assertOk()->json('data.0.preorder.id');

        $copy = Preorder::findOrFail($copyId);
        $this->assertNull($copy->event_id);
        $this->assertNull($copy->pickup_day);
    }

    public function test_a_pickup_day_that_no_longer_fits_the_event_is_dropped(): void
    {
        $event = Event::factory()->create(['start_date' => '2026-10-12', 'end_date' => '2026-10-13']);
        $source = $this->createPreorder(['event_id' => $event->id, 'pickup_day' => '2026-10-13']);
        $event->update(['end_date' => '2026-10-12']);
        // simulasi data lama yang tidak dibersihkan: pickup_day tetap di luar rentang
        Preorder::whereKey($source['id'])->update(['pickup_day' => '2026-10-13']);

        $copyId = $this->duplicate([$source['id']])->assertOk()->json('data.0.preorder.id');

        $copy = Preorder::findOrFail($copyId);
        $this->assertSame($event->id, $copy->event_id);
        $this->assertNull($copy->pickup_day);
    }

    public function test_a_soft_deleted_variant_fails_that_order_and_creates_nothing(): void
    {
        $variant = $this->variant();
        $source = $this->createPreorder([], [['variant_id' => $variant->id, 'qty' => 1]]);
        $variant->delete();
        $before = Preorder::count();

        $row = $this->duplicate([$source['id']])->assertOk()->json('data.0');

        $this->assertSame('failed', $row['status']);
        $this->assertNotEmpty($row['error']);
        $this->assertSame($before, Preorder::count());
    }

    public function test_an_inactive_variant_or_product_fails_that_order(): void
    {
        $inactiveVariant = $this->variant();
        $inactiveProduct = $this->variant();
        $a = $this->createPreorder([], [['variant_id' => $inactiveVariant->id, 'qty' => 1]]);
        $b = $this->createPreorder([], [['variant_id' => $inactiveProduct->id, 'qty' => 1]]);
        $inactiveVariant->update(['is_active' => false]);
        $inactiveProduct->product->update(['is_active' => false]);
        $before = Preorder::count();

        $data = $this->duplicate([$a['id'], $b['id']])->assertOk()->json('data');

        $this->assertSame(['failed', 'failed'], array_column($data, 'status'));
        $this->assertSame($before, Preorder::count());
    }

    public function test_a_repriced_discount_larger_than_the_new_total_fails_that_order(): void
    {
        $variant = $this->variant(100000);
        $source = $this->createPreorder(['discount' => 90000], [['variant_id' => $variant->id, 'qty' => 1]]);
        $variant->update(['sell_price' => 50000]);

        $row = $this->duplicate([$source['id']])->assertOk()->json('data.0');

        $this->assertSame('failed', $row['status']);
        $this->assertNotEmpty($row['error']);
    }

    // --- US2: banyak sekaligus ---------------------------------------------

    public function test_several_orders_become_several_independent_copies(): void
    {
        $sources = [$this->createPreorder(), $this->createPreorder(), $this->createPreorder()];
        $ids = array_column($sources, 'id');

        $data = $this->duplicate($ids)->assertOk()->json('data');

        $this->assertCount(3, $data);
        $this->assertSame($ids, array_column($data, 'source_id'));
        $newIds = array_column(array_column($data, 'preorder'), 'id');
        $this->assertCount(3, array_unique($newIds));
        foreach ($data as $i => $row) {
            $this->assertSame('created', $row['status']);
            $this->assertSame($ids[$i], $row['preorder']['source']['preorder_id']);
        }
        $this->assertSame(6, Preorder::count());
    }

    public function test_twenty_orders_can_be_duplicated_in_one_call(): void
    {
        $variant = $this->variant();
        $ids = [];
        for ($i = 0; $i < 20; $i++) {
            $ids[] = $this->createPreorder([], [['variant_id' => $variant->id, 'qty' => 1]])['id'];
        }

        $data = $this->duplicate($ids)->assertOk()->json('data');

        $this->assertCount(20, $data);
        $this->assertSame(20, collect($data)->where('status', 'created')->count());
        $this->assertSame(40, Preorder::count());
    }

    public function test_one_bad_order_does_not_stop_the_others(): void
    {
        $good1 = $this->createPreorder();
        $badVariant = $this->variant();
        $bad = $this->createPreorder([], [['variant_id' => $badVariant->id, 'qty' => 1]]);
        $good2 = $this->createPreorder();
        $badVariant->delete();

        $data = $this->duplicate([$good1['id'], $bad['id'], $good2['id']])->assertOk()->json('data');

        $this->assertSame(['created', 'failed', 'created'], array_column($data, 'status'));
        $this->assertSame(5, Preorder::count()); // 3 sumber + 2 salinan
    }

    public function test_an_id_from_the_other_data_mode_is_reported_as_not_found(): void
    {
        $liveSource = $this->createPreorder();
        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'demo', 'type' => 'string', 'group' => 'system']);

        $row = $this->duplicate([$liveSource['id']])->assertOk()->json('data.0');

        $this->assertSame('failed', $row['status']);
        $this->assertSame(1, Preorder::withoutGlobalScopes()->count());
    }

    public function test_the_ids_array_is_validated(): void
    {
        $this->postJson('/api/v1/preorders/duplicate', [])->assertStatus(422);
        $this->postJson('/api/v1/preorders/duplicate', ['preorder_ids' => []])->assertStatus(422);
        $this->postJson('/api/v1/preorders/duplicate', ['preorder_ids' => [999999]])->assertStatus(422);
        $this->postJson('/api/v1/preorders/duplicate', ['preorder_ids' => range(1, 101)])->assertStatus(422);
    }

    public function test_the_summary_counts_the_new_orders_immediately(): void
    {
        $source = $this->createPreorder();
        $this->assertSame(1, $this->getJson('/api/v1/preorders/summary')->json('transaction_count'));

        $this->duplicate([$source['id']])->assertOk();

        $summary = $this->getJson('/api/v1/preorders/summary')->assertOk()->json();
        $this->assertSame(2, $summary['transaction_count']);
    }
}
