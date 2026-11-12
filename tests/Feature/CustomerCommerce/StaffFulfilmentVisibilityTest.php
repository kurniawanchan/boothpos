<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\ActivityLog;
use App\Models\CustomerAccount;
use App\Models\CustomerOrder;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §8, §9) — apa yang TERLIHAT setelah staf
 * memproses pesanan:
 *  - pemilik pesanan melihat status, ongkir, pengiriman, dan linimasa lewat
 *    rute pelanggan; pelanggan lain mendapat 404;
 *  - detail audit staf (`metadata`, pelaku, nama staf) hanya ada di resource
 *    staf dan tidak pernah bocor ke respons pelanggan;
 *  - setiap transisi meninggalkan tepat satu baris log aktivitas tanpa data
 *    pribadi pelanggan.
 */
class StaffFulfilmentVisibilityTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    private const STAFF_NAME = 'Zulkarnaen Petugas Gudang';

    /** @var array<string,mixed> */
    private array $world;

    private User $staff;

    private CustomerAccount $account;

    private string $customerToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCustomerCommerce();
        $this->world = $this->buildStorefrontWorld();
        $this->staff = $this->fulfilmentStaff(null, [], ['name' => self::STAFF_NAME]);
        [$this->account, $this->customerToken] = $this->customerWithToken(
            ['email' => 'rina.pembeli@example.test'],
            ['name' => 'Rina Pembeli Setia', 'phone' => '081299990001'],
        );
    }

    private function act(CustomerOrder $order, string $action, array $body = []): TestResponse
    {
        return $this->asStaff($this->staff)->postJson("/api/v1/customer-orders/{$order->id}/{$action}", $body);
    }

    private function courierOrder(): CustomerOrder
    {
        $address = $this->makeCustomerAddress($this->account, [
            'recipient_name' => 'Rina Pembeli Setia', 'phone' => '081299990001', 'address_line' => 'Jl. Kenanga Rahasia No. 7',
        ]);

        return $this->placeOrderThroughCheckout($this->customerToken, [[$this->world['variantA']->id, 2]], [
            'fulfilment_method' => 'courier', 'address_id' => $address->id, 'notes' => 'Titip ke satpam Pak Joko',
        ]);
    }

    /** Teks yang tidak boleh muncul di respons PELANGGAN mana pun. */
    private function assertNoStaffDetail(TestResponse $response): void
    {
        $body = $response->getContent();

        foreach (['metadata', 'actor', self::STAFF_NAME, 'old_shipping_cost', 'new_total', 'stock_deducted', 'stock_restored', 'available_actions', 'available_stock', 'customer_id', '"id"'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "Respons pelanggan memuat detail staf: {$needle}");
        }
    }

    // ------------------------------------------------------------------
    // Pelanggan melihat hasil kerja staf
    // ------------------------------------------------------------------

    public function test_the_owning_customer_sees_the_shipment_and_timeline_after_staff_ship_and_update_tracking(): void
    {
        Carbon::setTestNow('2026-11-12 09:00:00');
        $order = $this->courierOrder();

        Carbon::setTestNow('2026-11-12 09:05:00');
        $this->act($order, 'confirm', ['shipping_cost' => 12000, 'note' => 'Pesanan dikonfirmasi'])->assertOk();
        Carbon::setTestNow('2026-11-12 09:10:00');
        $this->act($order, 'pack')->assertOk();
        Carbon::setTestNow('2026-11-12 09:20:00');
        $this->act($order, 'ship', ['courier_name' => 'JNE', 'service_name' => 'REG', 'tracking_number' => 'JNE-0001', 'note' => 'Diserahkan ke kurir'])->assertOk();
        Carbon::setTestNow('2026-11-12 09:30:00');
        $this->asStaff($this->staff)->patchJson("/api/v1/customer-orders/{$order->id}/shipment", ['tracking_number' => 'JNE-0002'])->assertOk();

        $at = fn (string $time) => Carbon::parse("2026-11-12 {$time}")->toIso8601String();

        $tracking = $this->bearer($this->customerToken)->getJson("/api/v1/customer/orders/{$order->order_number}/tracking");

        $tracking->assertOk()->assertExactJson(['data' => [
            'order_number' => $order->order_number,
            'status' => 'shipped',
            'fulfilment_method' => 'courier',
            'updated_by' => 'store',
            'shipment' => [
                'courier_name' => 'JNE',
                'service_name' => 'REG',
                'tracking_number' => 'JNE-0002',
                'tracking_url' => null,
                'dispatched_at' => $at('09:20:00'),
                'delivered_at' => null,
                'shipping_cost' => '12000.00',
            ],
            // Terbaru lebih dulu; tiap peristiwa hanya empat field publik.
            'timeline' => [
                ['status' => 'shipped', 'event_type' => 'tracking_updated', 'note' => null, 'occurred_at' => $at('09:30:00')],
                ['status' => 'shipped', 'event_type' => 'status_changed', 'note' => 'Diserahkan ke kurir', 'occurred_at' => $at('09:20:00')],
                ['status' => 'packing', 'event_type' => 'status_changed', 'note' => null, 'occurred_at' => $at('09:10:00')],
                ['status' => 'confirmed', 'event_type' => 'status_changed', 'note' => 'Pesanan dikonfirmasi', 'occurred_at' => $at('09:05:00')],
                ['status' => 'confirmed', 'event_type' => 'shipping_cost_set', 'note' => null, 'occurred_at' => $at('09:05:00')],
                ['status' => 'awaiting_confirmation', 'event_type' => 'status_changed', 'note' => null, 'occurred_at' => $at('09:00:00')],
            ],
        ]]);
        $this->assertNoStaffDetail($tracking);

        // Detail pesanan: ongkir dan total sudah final, pengiriman dan linimasa yang sama.
        $detail = $this->bearer($this->customerToken)->getJson("/api/v1/customer/orders/{$order->order_number}");
        $detail->assertOk()
            ->assertJsonPath('data.status', 'shipped')
            ->assertJsonPath('data.subtotal', '50000.00')
            ->assertJsonPath('data.shipping_cost', '12000.00')
            ->assertJsonPath('data.shipping_cost_pending', false)
            ->assertJsonPath('data.total', '62000.00')
            ->assertJsonPath('data.shipment', $tracking->json('data.shipment'))
            ->assertJsonPath('data.timeline', $tracking->json('data.timeline'));
        $this->assertNoStaffDetail($detail);

        // Daftar "Pesanan saya" ikut berubah.
        $this->bearer($this->customerToken)->getJson('/api/v1/customer/orders')->assertOk()
            ->assertJsonPath('data.0.status', 'shipped')
            ->assertJsonPath('data.0.total', '62000.00');

        // Setelah ditandai terkirim, pelanggan melihat waktu terimanya.
        Carbon::setTestNow('2026-11-13 14:00:00');
        $this->act($order, 'deliver', ['note' => 'Diterima penerima'])->assertOk();

        $done = $this->bearer($this->customerToken)->getJson("/api/v1/customer/orders/{$order->order_number}/tracking")->assertOk();
        $done->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.shipment.delivered_at', Carbon::parse('2026-11-13 14:00:00')->toIso8601String())
            ->assertJsonPath('data.timeline.0', ['status' => 'delivered', 'event_type' => 'status_changed', 'note' => 'Diterima penerima', 'occurred_at' => Carbon::parse('2026-11-13 14:00:00')->toIso8601String()]);
        $this->assertNoStaffDetail($done);
    }

    public function test_another_customer_cannot_see_the_fulfilled_order(): void
    {
        $order = $this->courierOrder();
        $this->act($order, 'confirm', ['shipping_cost' => 12000])->assertOk();
        $this->act($order, 'pack')->assertOk();
        $this->act($order, 'ship', ['courier_name' => 'JNE', 'tracking_number' => 'JNE-0001'])->assertOk();

        [, $otherToken] = $this->customerWithToken();

        foreach (["/api/v1/customer/orders/{$order->order_number}", "/api/v1/customer/orders/{$order->order_number}/tracking"] as $uri) {
            $response = $this->bearer($otherToken)->getJson($uri);

            $response->assertStatus(404)->assertJsonPath('code', 'not_found');
            $this->assertStringNotContainsString('JNE-0001', $response->getContent());
            $this->assertStringNotContainsString($order->order_number, $response->getContent());
        }
        $this->bearer($otherToken)->getJson('/api/v1/customer/orders')->assertOk()->assertJsonPath('data', []);

        // Nomor yang tidak pernah ada dijawab dengan badan yang sama (tanpa request_id).
        $foreign = $this->bearer($otherToken)->getJson("/api/v1/customer/orders/{$order->order_number}/tracking")->json();
        $unknown = $this->bearer($otherToken)->getJson('/api/v1/customer/orders/CO-0000000000/tracking')->json();
        unset($foreign['request_id'], $unknown['request_id']);
        $this->assertSame($foreign, $unknown);
    }

    public function test_the_customer_sees_a_cancellation_with_its_reason(): void
    {
        $order = $this->placeOrderThroughCheckout($this->customerToken, [[$this->world['variantA']->id, 1]]);
        $this->act($order, 'confirm')->assertOk();
        $this->act($order, 'cancel', ['reason' => 'Barang rusak saat dikemas'])->assertOk();

        $detail = $this->bearer($this->customerToken)->getJson("/api/v1/customer/orders/{$order->order_number}")->assertOk();

        $detail->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Barang rusak saat dikemas')
            ->assertJsonPath('data.timeline.0.status', 'cancelled')
            ->assertJsonPath('data.timeline.0.note', 'Barang rusak saat dikemas')
            ->assertJsonPath('data.shipment', null);
        $this->assertNoStaffDetail($detail);
    }

    public function test_a_pickup_order_never_shows_a_shipment_to_the_customer(): void
    {
        $order = $this->placeOrderThroughCheckout($this->customerToken, [[$this->world['variantA']->id, 1]]);
        foreach (['confirm', 'pack', 'ready'] as $action) {
            $this->act($order, $action)->assertOk();
        }

        $tracking = $this->bearer($this->customerToken)->getJson("/api/v1/customer/orders/{$order->order_number}/tracking")->assertOk();

        $tracking->assertJsonPath('data.status', 'ready_for_pickup')
            ->assertJsonPath('data.shipment', null)
            ->assertJsonPath('data.updated_by', 'store');
        $this->assertSame(
            ['ready_for_pickup', 'packing', 'confirmed', 'awaiting_confirmation'],
            array_column($tracking->json('data.timeline'), 'status'),
        );
    }

    // ------------------------------------------------------------------
    // Resource staf
    // ------------------------------------------------------------------

    public function test_the_staff_detail_carries_event_metadata_and_the_actor_name(): void
    {
        Carbon::setTestNow('2026-11-12 09:00:00');
        $order = $this->courierOrder();
        Carbon::setTestNow('2026-11-12 09:05:00');
        $this->act($order, 'confirm', ['shipping_cost' => 12000, 'note' => 'Pesanan dikonfirmasi'])->assertOk();

        $item = $order->items()->sole();
        $events = DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->orderByDesc('id')->pluck('id')->all();
        $fresh = $order->fresh();
        $at = fn (string $time) => Carbon::parse("2026-11-12 {$time}")->toIso8601String();

        $response = $this->asStaff($this->staff)->getJson("/api/v1/customer-orders/{$order->id}");

        $response->assertOk();
        $this->assertEquals(['data' => [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => 'confirmed',
            'fulfilment_method' => 'courier',
            'contact_name' => 'Rina Pembeli Setia',
            'contact_phone' => '081299990001',
            'total' => '62000.00',
            'shipping_cost_pending' => false,
            'item_count' => 1,
            'first_item_name' => 'Gantungan Kunci Sakura — Merah',
            'created_at' => $at('09:00:00'),
            'updated_at' => $at('09:05:00'),
            'customer_id' => $this->account->customer_id,
            'contact' => ['name' => 'Rina Pembeli Setia', 'phone' => '081299990001', 'email' => 'rina.pembeli@example.test'],
            'shipping_address' => $fresh->shipping_address,
            'notes' => 'Titip ke satpam Pak Joko',
            'cancel_reason' => null,
            'subtotal' => '50000.00',
            'shipping_cost' => '12000.00',
            'stock_deducted_at' => $at('09:05:00'),
            'confirmed_at' => $at('09:05:00'),
            'completed_at' => null,
            'cancelled_at' => null,
            'items' => [[
                'id' => $item->id,
                'variant_id' => $this->world['variantA']->id,
                'sku' => $this->world['variantA']->sku,
                'name' => 'Gantungan Kunci Sakura — Merah',
                'unit_price' => '25000.00',
                'qty' => 2,
                'line_total' => '50000.00',
                'available_stock' => 8,
            ]],
            'shipment' => null,
            'timeline' => [
                [
                    'id' => $events[0], 'status' => 'confirmed', 'event_type' => 'status_changed', 'note' => 'Pesanan dikonfirmasi',
                    'occurred_at' => $at('09:05:00'), 'actor_type' => 'staff', 'actor_user_id' => $this->staff->id, 'actor_name' => self::STAFF_NAME,
                    'metadata' => ['action' => 'confirm', 'from' => 'awaiting_confirmation', 'stock_deducted' => true],
                ],
                [
                    'id' => $events[1], 'status' => 'confirmed', 'event_type' => 'shipping_cost_set', 'note' => null,
                    'occurred_at' => $at('09:05:00'), 'actor_type' => 'staff', 'actor_user_id' => $this->staff->id, 'actor_name' => self::STAFF_NAME,
                    'metadata' => ['old_shipping_cost' => '0.00', 'new_shipping_cost' => '12000.00', 'old_total' => '50000.00', 'new_total' => '62000.00'],
                ],
                [
                    'id' => $events[2], 'status' => 'awaiting_confirmation', 'event_type' => 'status_changed', 'note' => null,
                    'occurred_at' => $at('09:00:00'), 'actor_type' => 'customer', 'actor_user_id' => null, 'actor_name' => null,
                    'metadata' => null,
                ],
            ],
            'available_actions' => ['pack', 'cancel'],
            'can_update_shipment' => false,
        ]], $response->json());

        // Kunci idempotensi, mode data, dan id akun tidak pernah ikut, juga untuk staf.
        foreach (['idempotency', 'data_mode', 'customer_account_id', 'password'] as $needle) {
            $this->assertStringNotContainsString($needle, $response->getContent());
        }
    }

    public function test_the_staff_detail_does_not_run_a_query_per_item_or_event(): void
    {
        $small = $this->placeOrderThroughCheckout($this->customerToken, [[$this->world['variantA']->id, 1]]);
        $big = $this->placeOrderThroughCheckout($this->customerToken, [[$this->world['variantA']->id, 1], [$this->world['variantB']->id, 1]]);
        foreach (['confirm', 'pack', 'ready', 'pickup'] as $action) {
            $this->act($big, $action)->assertOk();
        }
        $token = $this->issueStaffToken($this->staff);

        $count = function (CustomerOrder $order) use ($token): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->bearer($token)->getJson("/api/v1/customer-orders/{$order->id}")->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $count($small); // pemanasan cache pengaturan

        // 1 item + 1 peristiwa (pelanggan) vs 2 item + 5 peristiwa: selisihnya hanya query pelaku staf (sekali).
        $this->assertLessThanOrEqual($count($small) + 1, $count($big));
    }

    // ------------------------------------------------------------------
    // Log aktivitas
    // ------------------------------------------------------------------

    /** Data pribadi pelanggan yang tidak boleh muncul di log aktivitas mana pun. */
    private function assertLogsHoldNoCustomerData(CustomerOrder $order, array $extra = []): void
    {
        $pii = array_merge([
            'Rina Pembeli Setia', '081299990001', 'rina.pembeli@example.test', 'Jl. Kenanga Rahasia', 'Titip ke satpam', 'Pak Joko',
        ], $extra);

        foreach (ActivityLog::where('entity_type', 'CustomerOrder')->where('entity_id', $order->id)->get() as $log) {
            $haystack = $log->description.' '.json_encode($log->old_values).' '.json_encode($log->new_values);

            foreach ($pii as $needle) {
                $this->assertStringNotContainsString($needle, $haystack, "Log {$log->action} memuat data pelanggan.");
            }
            $this->assertStringContainsString($order->order_number, (string) $log->description);
            $this->assertLessThanOrEqual(255, mb_strlen((string) $log->description));
        }
    }

    private function logsOf(CustomerOrder $order)
    {
        return ActivityLog::where('entity_type', 'CustomerOrder')->where('entity_id', $order->id)->orderBy('id')->get();
    }

    public function test_every_courier_transition_writes_one_activity_log_row_without_customer_data(): void
    {
        $order = $this->courierOrder();
        $this->act($order, 'confirm', ['shipping_cost' => 12000, 'note' => 'Halo Rina Pembeli Setia'])->assertOk();
        $this->act($order, 'pack')->assertOk();
        $this->act($order, 'ship', ['courier_name' => 'JNE', 'service_name' => 'REG', 'tracking_number' => 'JNE-0001'])->assertOk();
        $this->asStaff($this->staff)->patchJson("/api/v1/customer-orders/{$order->id}/shipment", ['tracking_number' => 'JNE-0002'])->assertOk();
        $this->act($order, 'deliver')->assertOk();

        $logs = $this->logsOf($order);

        $this->assertSame([
            'customer_order_placed',
            'customer_order_confirm',
            'customer_order_pack',
            'customer_order_ship',
            'customer_order_shipment_update',
            'customer_order_deliver',
        ], $logs->pluck('action')->all());

        // Checkout: pelakunya pelanggan, jadi tanpa id staf. Sisanya: staf yang melakukannya.
        $this->assertSame([null, $this->staff->id, $this->staff->id, $this->staff->id, $this->staff->id, $this->staff->id], $logs->pluck('user_id')->all());

        $this->assertSame(['status' => 'awaiting_confirmation'], $logs[1]->old_values);
        $this->assertEquals(['status' => 'confirmed', 'shipping_cost' => '12000.00', 'total' => '62000.00'], $logs[1]->new_values);
        $this->assertSame(['status' => 'confirmed'], $logs[2]->old_values);
        $this->assertSame(['status' => 'packing'], $logs[2]->new_values);
        $this->assertSame(['status' => 'packing'], $logs[3]->old_values);
        $this->assertSame('shipped', $logs[3]->new_values['status']);
        $this->assertSame('JNE', $logs[3]->new_values['courier_name']);
        $this->assertSame('JNE-0001', $logs[3]->new_values['tracking_number']);
        $this->assertSame(['tracking_number' => 'JNE-0001'], $logs[4]->old_values);
        $this->assertSame(['tracking_number' => 'JNE-0002'], $logs[4]->new_values);
        $this->assertSame(['status' => 'shipped'], $logs[5]->old_values);
        $this->assertSame('delivered', $logs[5]->new_values['status']);

        // Catatan staf untuk pelanggan (yang di sini menyebut nama) tidak disalin ke log.
        $this->assertLogsHoldNoCustomerData($order);
    }

    public function test_pickup_and_cancel_transitions_are_logged_too(): void
    {
        $pickup = $this->placeOrderThroughCheckout($this->customerToken, [[$this->world['variantA']->id, 1]], ['notes' => 'Titip ke satpam Pak Joko']);
        foreach (['confirm', 'pack', 'ready', 'pickup'] as $action) {
            $this->act($pickup, $action)->assertOk();
        }

        $this->assertSame(
            ['customer_order_placed', 'customer_order_confirm', 'customer_order_pack', 'customer_order_ready', 'customer_order_pickup'],
            $this->logsOf($pickup)->pluck('action')->all(),
        );
        $this->assertLogsHoldNoCustomerData($pickup);

        $cancelled = $this->placeOrderThroughCheckout($this->customerToken, [[$this->world['variantB']->id, 1]]);
        $this->act($cancelled, 'confirm')->assertOk();
        $this->act($cancelled, 'cancel', ['reason' => 'Rina Pembeli Setia minta batal lewat telepon 081299990001'])->assertOk();

        $log = $this->logsOf($cancelled)->last();
        $this->assertSame('customer_order_cancel', $log->action);
        $this->assertSame($this->staff->id, $log->user_id);
        $this->assertSame(['status' => 'confirmed'], $log->old_values);
        $this->assertSame(['status' => 'cancelled', 'stock_restored' => true], $log->new_values);
        // Alasan pembatalan adalah teks bebas untuk pelanggan: tersimpan di pesanan, TIDAK di log aktivitas.
        $this->assertLogsHoldNoCustomerData($cancelled, ['minta batal']);

        // Batal sebelum konfirmasi: log mencatat bahwa tidak ada stok yang dikembalikan.
        $early = $this->placeOrderThroughCheckout($this->customerToken, [[$this->world['variantB']->id, 1]]);
        $this->act($early, 'cancel', ['reason' => 'Pesanan ganda'])->assertOk();
        $this->assertSame(['status' => 'cancelled', 'stock_restored' => false], $this->logsOf($early)->last()->new_values);
    }

    public function test_a_refused_transition_is_not_logged(): void
    {
        $order = $this->placeOrderThroughCheckout($this->customerToken, [[$this->world['variantA']->id, 1]]);

        $this->act($order, 'pack')->assertStatus(409);
        $this->act($order, 'ship', ['courier_name' => 'JNE'])->assertStatus(409);
        $this->act($order, 'cancel', [])->assertStatus(422);

        $this->assertSame(['customer_order_placed'], $this->logsOf($order)->pluck('action')->all());
    }

    public function test_the_log_is_rolled_back_together_with_a_failed_confirmation(): void
    {
        [, $variant] = $this->makeStorefrontProduct(stock: 1);
        $order = $this->placeOrderThroughCheckout($this->customerToken, [[$variant->id, 1]]);
        app(StockService::class)->applyMovement($variant->fresh(), 'adjustment', -1, null, null, 'hilang');
        $logsBefore = ActivityLog::count();

        $this->act($order, 'confirm', ['note' => 'coba'])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');

        $this->assertSame($logsBefore, ActivityLog::count());
    }
}
