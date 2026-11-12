<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\ActivityLog;
use App\Models\CustomerAccount;
use App\Models\CustomerOrder;
use App\Models\User;
use App\Support\Couriers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §9) — transisi pesanan pelanggan oleh staf
 * lewat HTTP: alur ambil-di-tempat dan alur kurir dari checkout sampai
 * selesai, setiap transisi yang TIDAK sah (409, tanpa jejak), aturan metode
 * pemenuhan, validasi pengiriman, pembaruan resi (riwayat hanya-tambah),
 * dan pembatalan.
 *
 * Stok diuji di StaffFulfilmentStockTest; izin, mode data, dan saklar fitur
 * di StaffFulfilmentPermissionTest; apa yang dilihat pelanggan dan log
 * aktivitas di StaffFulfilmentVisibilityTest.
 */
class StaffFulfilmentTransitionTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

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
        $this->staff = $this->fulfilmentStaff();
        [$this->account, $this->customerToken] = $this->customerWithToken([], ['name' => 'Rina Pelanggan', 'phone' => '081200000001']);
    }

    private function act(CustomerOrder $order, string $action, array $body = []): TestResponse
    {
        return $this->asStaff($this->staff)->postJson("/api/v1/customer-orders/{$order->id}/{$action}", $body);
    }

    private function patchShipment(CustomerOrder $order, array $body): TestResponse
    {
        return $this->asStaff($this->staff)->patchJson("/api/v1/customer-orders/{$order->id}/shipment", $body);
    }

    /** Baris riwayat mentah pesanan, urut penulisan (untuk membuktikan baris lama tidak berubah sebyte pun). */
    private function rawEvents(CustomerOrder $order): array
    {
        return DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->orderBy('id')->get()
            ->map(fn ($row) => json_encode($row))->all();
    }

    private function eventStatuses(CustomerOrder $order): array
    {
        return DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->orderBy('id')
            ->get()->map(fn ($row) => "{$row->event_type}:{$row->status}")->all();
    }

    // ------------------------------------------------------------------
    // Alur penuh
    // ------------------------------------------------------------------

    public function test_the_full_pickup_path_from_checkout_to_picked_up(): void
    {
        $order = $this->placeOrderThroughCheckout($this->customerToken, [[$this->world['variantA']->id, 2], [$this->world['variantB']->id, 1]]);
        $this->assertSame('awaiting_confirmation', $order->status);

        $this->act($order, 'confirm', ['note' => 'Pesanan diterima toko'])->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.available_actions', ['pack', 'cancel'])
            ->assertJsonPath('data.can_update_shipment', false);
        $this->assertNotNull($order->fresh()->confirmed_at);
        $this->assertNotNull($order->fresh()->stock_deducted_at);

        $this->act($order, 'pack')->assertOk()
            ->assertJsonPath('data.status', 'packing')
            ->assertJsonPath('data.available_actions', ['ready', 'cancel']);

        $this->act($order, 'ready', ['note' => 'Silakan ambil di booth A12'])->assertOk()
            ->assertJsonPath('data.status', 'ready_for_pickup')
            ->assertJsonPath('data.available_actions', ['pickup', 'cancel']);
        $this->assertNull($order->fresh()->completed_at);

        $this->act($order, 'pickup')->assertOk()
            ->assertJsonPath('data.status', 'picked_up')
            ->assertJsonPath('data.available_actions', [])
            ->assertJsonPath('data.shipment', null);

        $fresh = $order->fresh();
        $this->assertSame('picked_up', $fresh->status);
        $this->assertNotNull($fresh->completed_at);
        $this->assertNull($fresh->cancelled_at);

        $this->assertSame([
            'status_changed:awaiting_confirmation',
            'status_changed:confirmed',
            'status_changed:packing',
            'status_changed:ready_for_pickup',
            'status_changed:picked_up',
        ], $this->eventStatuses($order));

        // Peristiwa staf mencatat pelakunya; peristiwa checkout milik pelanggan.
        $events = DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->orderBy('id')->get();
        $this->assertSame(['customer', 'staff', 'staff', 'staff', 'staff'], $events->pluck('actor_type')->all());
        $this->assertSame([null, $this->staff->id, $this->staff->id, $this->staff->id, $this->staff->id], $events->pluck('actor_user_id')->all());
        $this->assertSame('Pesanan diterima toko', $events[1]->note);
        $this->assertSame('Silakan ambil di booth A12', $events[3]->note);

        // Pesanan pelanggan selesai sebagai catatannya sendiri: tidak ada pengiriman, penjualan POS, atau pre-order.
        $this->assertSame(0, DB::table('customer_order_shipments')->count());
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('preorders')->count());
    }

    public function test_the_full_courier_path_from_checkout_to_delivered(): void
    {
        $address = $this->makeCustomerAddress($this->account);
        $order = $this->placeOrderThroughCheckout($this->customerToken, [[$this->world['variantA']->id, 2]], [
            'fulfilment_method' => 'courier', 'address_id' => $address->id,
        ]);
        $this->assertSame('50000.00', $order->total);
        $this->assertTrue($order->shippingCostPending());

        // Konfirmasi sekaligus menetapkan ongkir: ongkir + total berubah bersama.
        $this->act($order, 'confirm', ['shipping_cost' => 15000])->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.subtotal', '50000.00')
            ->assertJsonPath('data.shipping_cost', '15000.00')
            ->assertJsonPath('data.total', '65000.00')
            ->assertJsonPath('data.shipping_cost_pending', false);

        $this->act($order, 'pack')->assertOk()->assertJsonPath('data.available_actions', ['ship', 'cancel']);

        $this->act($order, 'ship', [
            'courier_name' => 'JNE', 'service_name' => 'REG', 'tracking_number' => 'JNE-123456', 'note' => 'Dikirim sore ini',
        ])->assertOk()
            ->assertJsonPath('data.status', 'shipped')
            ->assertJsonPath('data.available_actions', ['deliver'])
            ->assertJsonPath('data.can_update_shipment', true)
            ->assertJsonPath('data.shipment.courier_name', 'JNE')
            ->assertJsonPath('data.shipment.service_name', 'REG')
            ->assertJsonPath('data.shipment.tracking_number', 'JNE-123456')
            ->assertJsonPath('data.shipment.tracking_url', null)
            ->assertJsonPath('data.shipment.delivered_at', null)
            ->assertJsonPath('data.shipment.shipping_cost', '15000.00');

        $shipment = DB::table('customer_order_shipments')->where('customer_order_id', $order->id)->sole();
        $this->assertSame('15000.00', $shipment->shipping_cost, 'Ongkir pengiriman = snapshot ongkir pesanan saat dikirim.');
        $this->assertNotNull($shipment->dispatched_at);
        $this->assertNull($order->fresh()->completed_at);

        $this->patchShipment($order, ['tracking_number' => 'JNE-999999'])->assertOk()
            ->assertJsonPath('data.status', 'shipped')
            ->assertJsonPath('data.shipment.tracking_number', 'JNE-999999');

        $this->act($order, 'deliver')->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.available_actions', [])
            ->assertJsonPath('data.can_update_shipment', false);

        $fresh = $order->fresh();
        $this->assertSame('delivered', $fresh->status);
        $this->assertNotNull($fresh->completed_at);
        $this->assertNotNull($fresh->shipment->delivered_at);
        $this->assertSame('65000.00', $fresh->total);

        $this->assertSame([
            'status_changed:awaiting_confirmation',
            'shipping_cost_set:confirmed',
            'status_changed:confirmed',
            'status_changed:packing',
            'status_changed:shipped',
            'tracking_updated:shipped',
            'status_changed:delivered',
        ], $this->eventStatuses($order));
        $this->assertSame(1, DB::table('customer_order_shipments')->count());
    }

    public function test_a_courier_order_cannot_be_confirmed_without_a_shipping_cost(): void
    {
        $order = $this->makeOrderInStatus('awaiting_confirmation', [[$this->world['variantA'], 2]], courier: true);
        $rowBefore = json_encode(DB::table('customer_orders')->where('id', $order->id)->first());

        // Dulu `{}` diam-diam menjadikan ongkir 0 dan tidak ada jalan membetulkannya sesudahnya.
        foreach ([[], ['shipping_cost' => null], ['shipping_cost' => ''], ['note' => 'Diterima']] as $body) {
            $response = $this->act($order, 'confirm', $body);

            $response->assertStatus(422)->assertJsonValidationErrors(['shipping_cost']);
            $this->assertSame(['message', 'errors'], array_keys($response->json()), 'Amplop validasi staf.');
            $this->assertNotEmpty($response->json('errors.shipping_cost.0'));
            $this->assertStringNotContainsString('validation.', $response->json('errors.shipping_cost.0'));
        }

        // Tidak ada yang tertulis: status, stok, riwayat, dan baris pesanan utuh.
        $this->assertSame($rowBefore, json_encode(DB::table('customer_orders')->where('id', $order->id)->first()));
        $this->assertSame(10, $this->world['variantA']->fresh()->current_stock);
        $this->assertSame(0, DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->count());
        $this->assertSame(0, ActivityLog::where('entity_type', 'CustomerOrder')->count());
    }

    public function test_the_shipping_cost_requirement_message_follows_the_staff_language(): void
    {
        $order = $this->makeOrderInStatus('awaiting_confirmation', [[$this->world['variantA'], 1]], courier: true);

        $this->assertSame(
            ['Enter the shipping cost to confirm a courier order (enter 0 if shipping is free).'],
            $this->act($order, 'confirm')->json('errors.shipping_cost'),
        );

        // Bahasa mengikuti `users.language` staf (bukan Accept-Language, itu milik pelanggan).
        $this->staff->update(['language' => 'id']);
        $this->assertSame(
            ['Isi ongkos kirim untuk mengonfirmasi pesanan kurir (isi 0 bila gratis).'],
            $this->act($order, 'confirm')->json('errors.shipping_cost'),
        );
    }

    public function test_an_explicit_zero_confirms_a_courier_order_with_free_shipping(): void
    {
        foreach ([0, '0', '0.00', 0.0] as $zero) {
            $order = $this->makeOrderInStatus('awaiting_confirmation', [[$this->world['variantA'], 1]], courier: true);

            $this->act($order, 'confirm', ['shipping_cost' => $zero])->assertOk()
                ->assertJsonPath('data.status', 'confirmed')
                ->assertJsonPath('data.shipping_cost', '0.00')
                ->assertJsonPath('data.shipping_cost_pending', false);

            $this->assertSame(['shipping_cost_set:confirmed', 'status_changed:confirmed'], $this->eventStatuses($order));
        }
    }

    public function test_a_pickup_order_still_confirms_without_a_shipping_cost(): void
    {
        $order = $this->makeOrderInStatus('awaiting_confirmation', [[$this->world['variantA'], 1]]);

        $this->act($order, 'confirm')->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.shipping_cost', '0.00');
        $this->assertSame(['status_changed:confirmed'], $this->eventStatuses($order));
    }

    public function test_a_courier_order_that_is_past_confirmation_still_answers_409_not_a_missing_shipping_cost(): void
    {
        $order = $this->makeOrderInStatus('confirmed', [[$this->world['variantA'], 1]], courier: true);

        $this->act($order, 'confirm')->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
    }

    public function test_confirming_an_unknown_order_is_a_404_not_a_validation_error(): void
    {
        $this->asStaff($this->staff)->postJson('/api/v1/customer-orders/987654/confirm', [])->assertNotFound();
    }

    public function test_the_service_itself_refuses_to_confirm_a_courier_order_without_a_shipping_cost(): void
    {
        $order = $this->makeOrderInStatus('awaiting_confirmation', [[$this->world['variantA'], 1]], courier: true);

        try {
            app(\App\Services\CustomerCommerce\CustomerOrderTransitionService::class)->confirm($order, $this->staff, []);
            $this->fail('Konfirmasi tanpa ongkir seharusnya ditolak.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('shipping_cost', $e->errors());
        }

        $this->assertSame('awaiting_confirmation', $order->fresh()->status);
        $this->assertSame(10, $this->world['variantA']->fresh()->current_stock);
    }

    public function test_a_free_shipping_cost_is_still_recorded_as_set(): void
    {
        $order = $this->makeOrderInStatus('awaiting_confirmation', [[$this->world['variantA'], 1]], courier: true);

        $this->act($order, 'confirm', ['shipping_cost' => '0'])->assertOk()->assertJsonPath('data.shipping_cost', '0.00');

        $event = DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->where('event_type', 'shipping_cost_set')->sole();
        $this->assertEquals(
            ['old_shipping_cost' => '0.00', 'new_shipping_cost' => '0.00', 'old_total' => '50000.00', 'new_total' => '50000.00'],
            json_decode($event->metadata, true),
        );
    }

    public function test_a_shipping_cost_is_refused_for_a_pickup_order_and_nothing_is_written(): void
    {
        $order = $this->makeOrderInStatus('awaiting_confirmation', [[$this->world['variantA'], 2]]);

        $this->act($order, 'confirm', ['shipping_cost' => 10000])
            ->assertStatus(422)->assertJsonValidationErrors(['shipping_cost']);

        $this->assertSame('awaiting_confirmation', $order->fresh()->status);
        $this->assertNull($order->fresh()->stock_deducted_at);
        $this->assertSame(10, $this->world['variantA']->fresh()->current_stock);
        $this->assertSame(0, DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->count());
    }

    public function test_the_shipping_cost_must_be_a_non_negative_amount_with_at_most_two_decimals(): void
    {
        $order = $this->makeOrderInStatus('awaiting_confirmation', [[$this->world['variantA'], 1]], courier: true);

        foreach ([-1, 'gratis', '10.005', '9999999999999'] as $bad) {
            $this->act($order, 'confirm', ['shipping_cost' => $bad])->assertStatus(422)->assertJsonValidationErrors(['shipping_cost']);
        }

        $this->assertSame('awaiting_confirmation', $order->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Transisi yang tidak sah
    // ------------------------------------------------------------------

    /** Badan permintaan yang SAH untuk tiap aksi, supaya yang ditolak adalah transisinya, bukan validasinya. */
    private const VALID_BODY = [
        'confirm' => [],
        'pack' => [],
        'ready' => [],
        'pickup' => [],
        'ship' => ['courier_name' => 'JNE'],
        'deliver' => [],
        'cancel' => ['reason' => 'Stok rusak'],
    ];

    /** Langkah yang sah, ditulis tangan (bukan diturunkan dari tabel service): "status|aksi|metode". */
    private const ALLOWED = [
        'awaiting_confirmation|confirm|pickup', 'awaiting_confirmation|confirm|courier',
        'confirmed|pack|pickup', 'confirmed|pack|courier',
        'packing|ready|pickup', 'ready_for_pickup|pickup|pickup',
        'packing|ship|courier', 'shipped|deliver|courier',
        'awaiting_confirmation|cancel|pickup', 'awaiting_confirmation|cancel|courier',
        'confirmed|cancel|pickup', 'confirmed|cancel|courier',
        'packing|cancel|pickup', 'packing|cancel|courier',
        'ready_for_pickup|cancel|pickup', 'ready_for_pickup|cancel|courier',
    ];

    private const METHOD_ONLY = ['ready' => 'pickup', 'pickup' => 'pickup', 'ship' => 'courier', 'deliver' => 'courier'];

    public function test_every_invalid_transition_is_refused_with_409_and_leaves_no_trace(): void
    {
        $statuses = ['awaiting_confirmation', 'confirmed', 'packing', 'ready_for_pickup', 'shipped', 'delivered', 'picked_up', 'cancelled'];
        $checked = 0;
        // Varian sendiri berstok banyak: 12 dari 16 pesanan di bawah sudah "memotong" stoknya.
        [, $variant] = $this->makeStorefrontProduct(['name' => 'Stiker Uji Transisi'], stock: 40);

        foreach (['pickup', 'courier'] as $method) {
            foreach ($statuses as $status) {
                $order = $this->makeOrderInStatus($status, [[$variant, 1]], courier: $method === 'courier');
                $rowBefore = json_encode(DB::table('customer_orders')->where('id', $order->id)->first());
                $movementsBefore = DB::table('stock_movements')->count();
                $shipmentsBefore = DB::table('customer_order_shipments')->count();
                $stockBefore = $variant->fresh()->current_stock;

                foreach (array_keys(self::VALID_BODY) as $action) {
                    $key = "{$status}|{$action}|{$method}";
                    if (in_array($key, self::ALLOWED, true)) {
                        continue;
                    }

                    $expected = isset(self::METHOD_ONLY[$action]) && self::METHOD_ONLY[$action] !== $method
                        ? 'wrong_fulfilment_method'
                        : 'invalid_transition';

                    $response = $this->act($order, $action, self::VALID_BODY[$action]);

                    $response->assertStatus(409)->assertJsonPath('code', $expected);
                    $this->assertNotEmpty($response->json('message'), $key);
                    $this->assertSame(['message', 'code'], array_keys($response->json()), "{$key}: amplop galat staf {message, code}");
                    $checked++;
                }

                // Tidak ada yang berubah untuk pesanan ini setelah seluruh percobaan yang ditolak.
                $this->assertSame($rowBefore, json_encode(DB::table('customer_orders')->where('id', $order->id)->first()), "{$status}/{$method}");
                $this->assertSame(0, DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->count(), "{$status}/{$method}");
                $this->assertSame($movementsBefore, DB::table('stock_movements')->count(), "{$status}/{$method}");
                $this->assertSame($shipmentsBefore, DB::table('customer_order_shipments')->count(), "{$status}/{$method}");
                $this->assertSame($stockBefore, $variant->fresh()->current_stock, "{$status}/{$method}");
            }
        }

        // 8 status × 7 aksi × 2 metode = 112, dikurangi 16 langkah yang sah.
        $this->assertSame(96, $checked);
        $this->assertSame(0, ActivityLog::where('entity_type', 'CustomerOrder')->count(), 'Transisi yang ditolak tidak masuk log aktivitas.');
    }

    public function test_a_pickup_order_cannot_be_shipped_or_receive_a_shipment(): void
    {
        $order = $this->makeOrderInStatus('packing', [[$this->world['variantA'], 1]]);

        $this->act($order, 'ship', ['courier_name' => 'JNE', 'tracking_number' => 'JNE-1'])
            ->assertStatus(409)->assertJsonPath('code', 'wrong_fulfilment_method');
        $this->patchShipment($order, ['tracking_number' => 'JNE-1'])
            ->assertStatus(409)->assertJsonPath('code', 'wrong_fulfilment_method');
        $this->act($order, 'deliver')->assertStatus(409)->assertJsonPath('code', 'wrong_fulfilment_method');

        $this->assertSame('packing', $order->fresh()->status);
        $this->assertSame(0, DB::table('customer_order_shipments')->count());
        $this->assertSame(0, DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->count());
    }

    public function test_a_courier_order_cannot_be_marked_ready_or_picked_up(): void
    {
        $order = $this->makeOrderInStatus('packing', [[$this->world['variantA'], 1]], courier: true);

        $this->act($order, 'ready')->assertStatus(409)->assertJsonPath('code', 'wrong_fulfilment_method');
        $this->act($order, 'pickup')->assertStatus(409)->assertJsonPath('code', 'wrong_fulfilment_method');

        $this->assertSame('packing', $order->fresh()->status);
        $this->assertNull($order->fresh()->completed_at);
    }

    public function test_an_unknown_order_is_404_for_every_action(): void
    {
        $missing = CustomerOrder::factory()->make();
        $missing->id = 999999;

        $this->asStaff($this->staff)->getJson('/api/v1/customer-orders/999999')->assertNotFound();
        foreach (self::VALID_BODY as $action => $body) {
            $this->act($missing, $action, $body)->assertNotFound();
        }
        $this->patchShipment($missing, ['tracking_number' => 'X-1'])->assertNotFound();
        $this->asStaff($this->staff)->getJson('/api/v1/customer-orders/CO-ABCDEFGHJK')->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Pengiriman
    // ------------------------------------------------------------------

    public function test_shipping_requires_a_courier_from_the_list(): void
    {
        $order = $this->makeOrderInStatus('packing', [[$this->world['variantA'], 1]], courier: true);

        $this->act($order, 'ship', ['tracking_number' => 'JNE-1'])
            ->assertStatus(422)->assertJsonValidationErrors(['courier_name']);
        $this->act($order, 'ship', ['courier_name' => 'Kurir Tetangga'])
            ->assertStatus(422)->assertJsonValidationErrors(['courier_name']);
        $this->act($order, 'ship', ['courier_name' => 'jne'])
            ->assertStatus(422)->assertJsonValidationErrors(['courier_name']);

        $this->assertSame('packing', $order->fresh()->status);
        $this->assertSame(0, DB::table('customer_order_shipments')->count());
    }

    public function test_every_courier_in_the_shared_list_is_accepted(): void
    {
        foreach (Couriers::OPTIONS as $courier) {
            $order = $this->makeOrderInStatus('packing', [[$this->world['variantB'], 1]], courier: true);

            $this->act($order, 'ship', ['courier_name' => $courier])->assertOk()
                ->assertJsonPath('data.shipment.courier_name', $courier)
                ->assertJsonPath('data.shipment.tracking_number', null);
        }
    }

    public function test_shipping_validates_the_tracking_number_service_and_dispatch_time(): void
    {
        $order = $this->makeOrderInStatus('packing', [[$this->world['variantA'], 1]], courier: true);

        $bad = [
            ['tracking_number' => 'JNE 123'],
            ['tracking_number' => 'https://evil.example/x'],
            ['tracking_number' => str_repeat('A', 51)],
            ['service_name' => str_repeat('S', 51)],
            ['service_name' => '<b>REG</b>'],
            ['dispatched_at' => now()->addDay()->toIso8601String()],
            ['dispatched_at' => 'kemarin sore'],
            ['note' => '<script>alert(1)</script>'],
            ['note' => str_repeat('n', 256)],
        ];

        foreach ($bad as $fields) {
            $this->act($order, 'ship', ['courier_name' => 'JNE'] + $fields)
                ->assertStatus(422)->assertJsonValidationErrors(array_keys($fields));
        }

        $this->assertSame('packing', $order->fresh()->status);
        $this->assertSame(0, DB::table('customer_order_shipments')->count());
    }

    public function test_a_backdated_dispatch_time_is_stored_in_the_application_timezone(): void
    {
        Carbon::setTestNow('2026-11-12 10:00:00');
        $order = $this->makeOrderInStatus('packing', [[$this->world['variantA'], 1]], courier: true);

        // 08:30 di zona +09:00 adalah 23:30 UTC hari sebelumnya; yang disimpan harus saat yang SAMA, bukan "08:30 lokal".
        $input = Carbon::parse('2026-11-12T08:30:00+09:00');

        $this->act($order, 'ship', ['courier_name' => 'SiCepat', 'dispatched_at' => '2026-11-12T08:30:00+09:00'])->assertOk();

        $stored = $order->fresh()->shipment->dispatched_at;
        $this->assertTrue($stored->equalTo($input), "tersimpan {$stored->toIso8601String()}");

        // Peristiwa tetap bercap waktu PENCATATAN, sehingga urutan linimasa tidak mundur.
        $event = $order->statusEvents()->first();
        $this->assertSame('shipped', $event->status);
        $this->assertTrue($event->occurred_at->equalTo(now()));
    }

    public function test_a_tracking_update_appends_an_event_and_never_rewrites_history(): void
    {
        $address = $this->makeCustomerAddress($this->account);
        $order = $this->placeOrderThroughCheckout($this->customerToken, [[$this->world['variantA']->id, 1]], [
            'fulfilment_method' => 'courier', 'address_id' => $address->id,
        ]);
        $this->act($order, 'confirm', ['shipping_cost' => 9000])->assertOk();
        $this->act($order, 'pack')->assertOk();
        $this->act($order, 'ship', ['courier_name' => 'JNE', 'service_name' => 'REG', 'tracking_number' => 'JNE-0001'])->assertOk();

        $before = $this->rawEvents($order);
        $this->assertCount(5, $before);

        $this->patchShipment($order, ['tracking_number' => 'JNE-0002', 'service_name' => 'YES'])->assertOk()
            ->assertJsonPath('data.status', 'shipped')
            ->assertJsonPath('data.shipment.tracking_number', 'JNE-0002')
            ->assertJsonPath('data.shipment.service_name', 'YES')
            ->assertJsonPath('data.shipment.courier_name', 'JNE');

        $after = $this->rawEvents($order);
        $this->assertCount(6, $after);
        $this->assertSame($before, array_slice($after, 0, 5), 'Baris riwayat lama harus identik byte demi byte.');

        $new = DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->orderByDesc('id')->first();
        $this->assertSame('tracking_updated', $new->event_type);
        $this->assertSame('shipped', $new->status);
        $this->assertSame('staff', $new->actor_type);
        $this->assertSame($this->staff->id, $new->actor_user_id);
        $this->assertNull($new->note);
        $this->assertEquals([
            'old' => ['tracking_number' => 'JNE-0001', 'service_name' => 'REG'],
            'new' => ['tracking_number' => 'JNE-0002', 'service_name' => 'YES'],
        ], json_decode($new->metadata, true));

        // Hanya kunci yang dikirim yang berubah; perubahan kedua juga baris baru.
        $this->patchShipment($order, ['tracking_number' => 'JNE-0003'])->assertOk()
            ->assertJsonPath('data.shipment.service_name', 'YES');
        $third = $this->rawEvents($order);
        $this->assertCount(7, $third);
        $this->assertSame($after, array_slice($third, 0, 6));
        $this->assertEquals(
            ['old' => ['tracking_number' => 'JNE-0002'], 'new' => ['tracking_number' => 'JNE-0003']],
            json_decode(DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->orderByDesc('id')->value('metadata'), true),
        );

        // Status, ongkir, dan total pesanan tidak tersentuh.
        $fresh = $order->fresh();
        $this->assertSame('shipped', $fresh->status);
        $this->assertSame('34000.00', $fresh->total);
        $this->assertSame(1, DB::table('customer_order_shipments')->count());
    }

    public function test_repeating_the_same_tracking_number_writes_nothing(): void
    {
        $order = $this->makeOrderInStatus('shipped', [[$this->world['variantA'], 1]], courier: true);

        $this->patchShipment($order, ['tracking_number' => 'JNE-0001', 'service_name' => 'REG'])->assertOk();

        $this->assertSame(0, DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->count());
        $this->assertSame(0, ActivityLog::where('entity_type', 'CustomerOrder')->count());
    }

    public function test_an_explicit_null_clears_the_tracking_number(): void
    {
        $order = $this->makeOrderInStatus('shipped', [[$this->world['variantA'], 1]], courier: true);

        $this->patchShipment($order, ['tracking_number' => null])->assertOk()
            ->assertJsonPath('data.shipment.tracking_number', null)
            ->assertJsonPath('data.shipment.service_name', 'REG');

        $this->assertEquals(
            ['old' => ['tracking_number' => 'JNE-0001'], 'new' => ['tracking_number' => null]],
            json_decode(DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->sole()->metadata, true),
        );
    }

    public function test_a_shipment_update_needs_a_field_and_valid_values(): void
    {
        $order = $this->makeOrderInStatus('shipped', [[$this->world['variantA'], 1]], courier: true);

        $this->patchShipment($order, [])->assertStatus(422)->assertJsonValidationErrors(['tracking_number']);
        $this->patchShipment($order, ['courier_name' => 'J&T'])->assertStatus(422)->assertJsonValidationErrors(['tracking_number']);
        $this->patchShipment($order, ['tracking_number' => 'ada spasi'])->assertStatus(422)->assertJsonValidationErrors(['tracking_number']);
        $this->patchShipment($order, ['tracking_number' => str_repeat('9', 51)])->assertStatus(422)->assertJsonValidationErrors(['tracking_number']);
        $this->patchShipment($order, ['service_name' => '<i>YES</i>'])->assertStatus(422)->assertJsonValidationErrors(['service_name']);

        $this->assertSame('JNE-0001', $order->fresh()->shipment->tracking_number);
        $this->assertSame('JNE', $order->fresh()->shipment->courier_name);
        $this->assertSame(0, DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->count());
    }

    public function test_the_shipment_can_only_be_updated_while_shipped(): void
    {
        $packing = $this->makeOrderInStatus('packing', [[$this->world['variantA'], 1]], courier: true);
        $delivered = $this->makeOrderInStatus('delivered', [[$this->world['variantA'], 1]], courier: true);

        $this->patchShipment($packing, ['tracking_number' => 'JNE-7'])->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
        $this->patchShipment($delivered, ['tracking_number' => 'JNE-7'])->assertStatus(409)->assertJsonPath('code', 'invalid_transition');

        $this->assertSame('JNE-0001', $delivered->fresh()->shipment->tracking_number);
        $this->assertSame(0, DB::table('customer_order_status_events')->count());
    }

    public function test_deliver_sets_delivered_at_and_completes_the_order(): void
    {
        Carbon::setTestNow('2026-11-12 15:00:00');
        $order = $this->makeOrderInStatus('shipped', [[$this->world['variantA'], 1]], courier: true);
        $dispatchedAt = $order->shipment->dispatched_at;

        $this->act($order, 'deliver', ['delivered_at' => '2026-11-12T14:59:30+00:00', 'note' => 'Diterima penerima'])
            ->assertOk()->assertJsonPath('data.status', 'delivered');

        $fresh = $order->fresh();
        $expected = Carbon::parse('2026-11-12T14:59:30+00:00');
        $this->assertTrue($fresh->shipment->delivered_at->equalTo($expected));
        $this->assertTrue($fresh->completed_at->equalTo($expected));
        $this->assertTrue($fresh->shipment->dispatched_at->equalTo($dispatchedAt), 'Waktu kirim tidak berubah.');

        $event = DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->sole();
        $this->assertSame('delivered', $event->status);
        $this->assertSame('Diterima penerima', $event->note);
    }

    public function test_deliver_defaults_to_now(): void
    {
        Carbon::setTestNow('2026-11-12 15:00:00');
        $order = $this->makeOrderInStatus('shipped', [[$this->world['variantA'], 1]], courier: true);

        $this->act($order, 'deliver')->assertOk();

        $this->assertTrue($order->fresh()->shipment->delivered_at->equalTo(now()));
        $this->assertTrue($order->fresh()->completed_at->equalTo(now()));
    }

    public function test_deliver_refuses_a_time_in_the_future_or_before_dispatch(): void
    {
        $order = $this->makeOrderInStatus('shipped', [[$this->world['variantA'], 1]], courier: true);

        $this->act($order, 'deliver', ['delivered_at' => now()->addHour()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors(['delivered_at']);
        $this->act($order, 'deliver', ['delivered_at' => now()->subDay()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors(['delivered_at']);

        $fresh = $order->fresh();
        $this->assertSame('shipped', $fresh->status);
        $this->assertNull($fresh->shipment->delivered_at);
        $this->assertNull($fresh->completed_at);
        $this->assertSame(0, DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->count());
    }

    // ------------------------------------------------------------------
    // Pembatalan (stok: lihat StaffFulfilmentStockTest)
    // ------------------------------------------------------------------

    public function test_cancel_works_from_every_allowed_state(): void
    {
        $cases = [
            ['awaiting_confirmation', false], ['confirmed', false], ['packing', false], ['ready_for_pickup', false],
            ['awaiting_confirmation', true], ['confirmed', true], ['packing', true],
        ];

        foreach ($cases as [$status, $courier]) {
            $order = $this->makeOrderInStatus($status, [[$this->world['variantA'], 1]], courier: $courier);

            $this->act($order, 'cancel', ['reason' => 'Barang cacat produksi'])->assertOk()
                ->assertJsonPath('data.status', 'cancelled')
                ->assertJsonPath('data.cancel_reason', 'Barang cacat produksi')
                ->assertJsonPath('data.available_actions', []);

            $fresh = $order->fresh();
            $this->assertSame('cancelled', $fresh->status, $status);
            $this->assertSame('Barang cacat produksi', $fresh->cancel_reason);
            $this->assertNotNull($fresh->cancelled_at);
            $this->assertNull($fresh->completed_at);

            $event = DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->sole();
            $this->assertSame('cancelled', $event->status);
            $this->assertSame('status_changed', $event->event_type);
            $this->assertSame('Barang cacat produksi', $event->note, 'Alasan pembatalan tampil di linimasa pelanggan.');
            $this->assertSame($status, json_decode($event->metadata, true)['from']);
        }
    }

    public function test_cancel_is_refused_once_goods_have_left_or_the_order_is_closed(): void
    {
        $cases = [['shipped', true], ['delivered', true], ['picked_up', false], ['cancelled', false], ['cancelled', true]];

        foreach ($cases as [$status, $courier]) {
            $order = $this->makeOrderInStatus($status, [[$this->world['variantA'], 1]], courier: $courier);

            $this->act($order, 'cancel', ['reason' => 'Pelanggan berubah pikiran'])
                ->assertStatus(409)->assertJsonPath('code', 'invalid_transition');

            $fresh = $order->fresh();
            $this->assertSame($status, $fresh->status);
            $this->assertNull($fresh->cancel_reason);
            $this->assertSame(0, DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->count());
        }
    }

    public function test_cancel_requires_a_plain_text_reason(): void
    {
        $order = $this->makeOrderInStatus('confirmed', [[$this->world['variantA'], 1]]);

        foreach ([[], ['reason' => ''], ['reason' => '   '], ['reason' => str_repeat('a', 256)], ['reason' => '<b>habis</b>'], ['reason' => ['x']]] as $body) {
            $this->act($order, 'cancel', $body)->assertStatus(422)->assertJsonValidationErrors(['reason']);
        }

        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertNull($order->fresh()->cancel_reason);
    }

    public function test_notes_must_be_plain_text_on_every_transition(): void
    {
        $order = $this->makeOrderInStatus('awaiting_confirmation', [[$this->world['variantA'], 1]]);

        $this->act($order, 'confirm', ['note' => '<a href="x">klik</a>'])->assertStatus(422)->assertJsonValidationErrors(['note']);
        $this->act($order, 'confirm', ['note' => "baris\x07bel"])->assertStatus(422)->assertJsonValidationErrors(['note']);
        $this->assertSame('awaiting_confirmation', $order->fresh()->status);

        // Tanda < dan & lepas adalah teks biasa.
        $this->act($order, 'confirm', ['note' => 'Stok < 5 & hampir habis'])->assertOk();
        $this->assertSame('Stok < 5 & hampir habis', DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->sole()->note);

        $this->act($order, 'pack', ['note' => '<i>x</i>'])->assertStatus(422)->assertJsonValidationErrors(['note']);
        $this->assertSame('confirmed', $order->fresh()->status);
    }
}
