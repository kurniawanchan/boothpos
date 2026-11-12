<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\CustomerAccount;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\CustomerOrderShipment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §8) — baca pesanan milik sendiri:
 * GET /customer/orders, /customer/orders/{orderNumber}, …/tracking.
 *
 * Yang dijaga: hanya pesanan pemilik token yang terlihat (nomor milik akun
 * lain = 404 yang sama dengan nomor yang tidak ada); detail audit staf
 * (`metadata`, id/nama pelaku) tidak pernah keluar; pelacakan adalah catatan
 * toko (`updated_by: "store"`), dengan `tracking_url` hanya dari templat
 * yang diizinkan server; dan semuanya tetap bisa dibaca saat saklar fitur mati.
 *
 * Riwayat status di sini ditulis langsung ke tabel untuk menyiapkan keadaan
 * (transisi staf adalah slice berikutnya); jalur tulis resminya tetap
 * CustomerOrderTransitionService.
 */
class CustomerOrderReadTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    private const URL = '/api/v1/customer/orders';

    private function orderFor(CustomerAccount $account, array $attrs = [], bool $courier = false): CustomerOrder
    {
        $factory = CustomerOrder::factory();

        return ($courier ? $factory->courier() : $factory)->create($attrs + [
            'customer_account_id' => $account->id,
            'customer_id' => $account->customer_id,
        ]);
    }

    private function event(CustomerOrder $order, string $status, string $occurredAt, array $attrs = []): void
    {
        DB::table('customer_order_status_events')->insert($attrs + [
            'customer_order_id' => $order->id,
            'status' => $status,
            'event_type' => 'status_changed',
            'note' => null,
            'actor_type' => 'staff',
            'actor_user_id' => null,
            'metadata' => null,
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
        ]);
    }

    public function test_the_list_is_paginated_newest_first_and_only_shows_own_orders(): void
    {
        [$account, $token] = $this->customerWithToken();
        [$other] = $this->customerWithToken();

        $old = $this->orderFor($account, ['created_at' => now()->subDays(3), 'total' => '10000.00']);
        $middle = $this->orderFor($account, ['created_at' => now()->subDays(2), 'status' => 'confirmed', 'total' => '20000.00']);
        $new = $this->orderFor($account, ['created_at' => now()->subDay(), 'total' => '30000.00'], courier: true);
        $foreign = $this->orderFor($other, ['created_at' => now()]);

        CustomerOrderItem::factory()->create(['customer_order_id' => $new->id, 'name_snapshot' => 'Poster A3 — Glossy', 'qty' => 3]);
        CustomerOrderItem::factory()->create(['customer_order_id' => $new->id, 'name_snapshot' => 'Stiker — Bulat', 'qty' => 5]);
        CustomerOrderItem::factory()->create(['customer_order_id' => $foreign->id, 'name_snapshot' => 'Barang Orang Lain']);

        $response = $this->bearer($token)->getJson(self::URL.'?per_page=2');

        $response->assertOk()
            ->assertJsonPath('meta', ['current_page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0', [
                'order_number' => $new->order_number,
                'status' => 'awaiting_confirmation',
                'fulfilment_method' => 'courier',
                'total' => '30000.00',
                'item_count' => 2,
                'first_item_name' => 'Poster A3 — Glossy',
                'created_at' => $new->created_at->toIso8601String(),
                'updated_at' => $new->updated_at->toIso8601String(),
            ])
            ->assertJsonPath('data.1.order_number', $middle->order_number)
            ->assertJsonPath('data.1.status', 'confirmed')
            ->assertJsonPath('data.1.item_count', 0)
            ->assertJsonPath('data.1.first_item_name', null);
        $this->assertSame(['data', 'meta'], array_keys($response->json()));

        $this->bearer($token)->getJson(self::URL.'?per_page=2&page=2')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_number', $old->order_number)
            ->assertJsonPath('meta.current_page', 2);

        $all = $this->bearer($token)->getJson(self::URL)->assertOk()->assertJsonPath('meta.per_page', 20);
        $this->assertSame([$new->order_number, $middle->order_number, $old->order_number], array_column($all->json('data'), 'order_number'));
        $this->assertStringNotContainsString($foreign->order_number, $all->getContent());
        $this->assertStringNotContainsString('Barang Orang Lain', $all->getContent());

        // Ringkasan tidak memuat kontak, alamat, maupun kunci internal.
        foreach (['contact', 'shipping_address', 'idempotency', 'customer_account_id', 'customer_id', 'data_mode', '"id"'] as $leak) {
            $this->assertStringNotContainsString($leak, $all->getContent());
        }
    }

    public function test_the_list_page_size_is_bounded_like_the_catalogue(): void
    {
        [$account, $token] = $this->customerWithToken();
        $this->orderFor($account);

        $this->bearer($token)->getJson(self::URL.'?per_page=500')->assertOk()->assertJsonPath('meta.per_page', 50);
        $this->bearer($token)->getJson(self::URL.'?page=99')->assertOk()->assertExactJson([
            'data' => [], 'meta' => ['current_page' => 99, 'per_page' => 20, 'total' => 1, 'last_page' => 1],
        ]);

        foreach (['per_page=0', 'per_page=abc', 'page=0', 'page=-1', 'page=100001'] as $query) {
            $this->bearer($token)->getJson(self::URL.'?'.$query)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        }
    }

    public function test_the_list_does_not_run_one_query_per_order(): void
    {
        [$account, $token] = $this->customerWithToken();

        // Hanya query ke tabel pesanan yang dihitung: query autentikasi bisa bertambah/berkurang satu
        // (Sanctum hanya menulis `last_used_at` bila detiknya berganti) dan bukan urusan test ini.
        $count = function () use ($token): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->bearer($token)->getJson(self::URL)->assertOk();
            DB::disableQueryLog();

            return count(array_filter(DB::getQueryLog(), fn (array $query) => str_contains($query['query'], 'customer_order')));
        };

        $first = $this->orderFor($account);
        CustomerOrderItem::factory()->create(['customer_order_id' => $first->id]);
        $withOne = $count();

        foreach (range(1, 8) as $i) {
            $order = $this->orderFor($account);
            CustomerOrderItem::factory()->count(2)->create(['customer_order_id' => $order->id]);
        }

        // Hitung total + satu halaman (jumlah baris dan nama barang pertama ikut sebagai subquery).
        $this->assertSame(2, $withOne);
        $this->assertSame($withOne, $count());
    }

    public function test_detail_of_a_shipped_courier_order_shows_shipment_and_timeline_newest_first(): void
    {
        Setting::updateOrCreate(['key' => 'customer_commerce_courier_instructions'], ['value' => 'Paket dikirim H+1.']);
        Setting::updateOrCreate(['key' => 'customer_commerce_pickup_instructions'], ['value' => 'Ambil di booth A12.']);
        $staff = User::factory()->create(['name' => 'Staf Rahasia', 'role' => 'admin']);
        [$account, $token] = $this->customerWithToken();

        $order = $this->orderFor($account, [
            'status' => 'shipped', 'subtotal' => '50000.00', 'shipping_cost' => '12000.00', 'total' => '62000.00',
            'contact_name' => 'Rina Pelanggan', 'contact_phone' => '081200000001', 'contact_email' => 'rina@example.test',
            'notes' => 'Tolong dibungkus', 'created_at' => '2026-11-01 08:00:00',
        ], courier: true);
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id, 'name_snapshot' => 'Poster A3 — Glossy', 'sku_snapshot' => 'SKU-POS-001',
            'unit_price' => '25000.00', 'qty' => 2, 'line_total' => '50000.00',
        ]);
        $order->shipment()->create([
            'courier_name' => 'JNE', 'service_name' => 'REG', 'tracking_number' => 'JNE-123 456',
            'shipping_cost' => '12000.00', 'dispatched_at' => '2026-11-03 10:00:00',
        ]);

        $this->event($order, 'awaiting_confirmation', '2026-11-01 08:00:00', ['actor_type' => 'customer']);
        $this->event($order, 'confirmed', '2026-11-02 09:00:00', ['actor_user_id' => $staff->id, 'note' => 'Pesanan dikonfirmasi.', 'metadata' => json_encode(['internal' => 'catatan-audit-staf'])]);
        $this->event($order, 'confirmed', '2026-11-02 09:00:00', ['event_type' => 'shipping_cost_set', 'actor_user_id' => $staff->id, 'metadata' => json_encode(['old' => '0.00', 'new' => '12000.00'])]);
        $this->event($order, 'packing', '2026-11-02 15:00:00', ['actor_user_id' => $staff->id]);
        $this->event($order, 'shipped', '2026-11-03 10:00:00', ['actor_user_id' => $staff->id, 'note' => 'Dikirim lewat JNE.']);

        $response = $this->bearer($token)->getJson(self::URL.'/'.$order->order_number);
        $fresh = $order->fresh();
        $iso = fn (string $at): string => Carbon::parse($at)->toIso8601String();

        $response->assertOk()->assertExactJson(['data' => [
            'order_number' => $order->order_number,
            'status' => 'shipped',
            'fulfilment_method' => 'courier',
            'total' => '62000.00',
            'item_count' => 1,
            'first_item_name' => 'Poster A3 — Glossy',
            'created_at' => $fresh->created_at->toIso8601String(),
            'updated_at' => $fresh->updated_at->toIso8601String(),
            'subtotal' => '50000.00',
            'shipping_cost' => '12000.00',
            'shipping_cost_pending' => false,
            'contact' => ['name' => 'Rina Pelanggan', 'phone' => '081200000001', 'email' => 'rina@example.test'],
            'shipping_address' => [
                'recipient_name' => 'Penerima Uji', 'phone' => '0800-0000-0000', 'address_line' => 'Jl. Contoh No. 1',
                'city' => 'Bandung', 'province' => 'Jawa Barat', 'postal_code' => '40111', 'notes' => null,
            ],
            'notes' => 'Tolong dibungkus',
            'cancel_reason' => null,
            'instructions' => 'Paket dikirim H+1.',
            'items' => [
                ['name' => 'Poster A3 — Glossy', 'sku' => 'SKU-POS-001', 'unit_price' => '25000.00', 'qty' => 2, 'line_total' => '50000.00'],
            ],
            'shipment' => [
                'courier_name' => 'JNE', 'service_name' => 'REG', 'tracking_number' => 'JNE-123 456', 'tracking_url' => null,
                'dispatched_at' => $iso('2026-11-03 10:00:00'), 'delivered_at' => null, 'shipping_cost' => '12000.00',
            ],
            'timeline' => [
                ['status' => 'shipped', 'event_type' => 'status_changed', 'note' => 'Dikirim lewat JNE.', 'occurred_at' => $iso('2026-11-03 10:00:00')],
                ['status' => 'packing', 'event_type' => 'status_changed', 'note' => null, 'occurred_at' => $iso('2026-11-02 15:00:00')],
                ['status' => 'confirmed', 'event_type' => 'shipping_cost_set', 'note' => null, 'occurred_at' => $iso('2026-11-02 09:00:00')],
                ['status' => 'confirmed', 'event_type' => 'status_changed', 'note' => 'Pesanan dikonfirmasi.', 'occurred_at' => $iso('2026-11-02 09:00:00')],
                ['status' => 'awaiting_confirmation', 'event_type' => 'status_changed', 'note' => null, 'occurred_at' => $iso('2026-11-01 08:00:00')],
            ],
        ]]);

        // Detail audit staf tidak pernah ikut.
        foreach (['catatan-audit-staf', 'metadata', 'actor', 'Staf Rahasia', 'idempotency', 'customer_account_id', 'stock_deducted_at'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }
    }

    public function test_tracking_reports_store_maintained_records(): void
    {
        [$account, $token] = $this->customerWithToken();
        $order = $this->orderFor($account, ['status' => 'delivered'], courier: true);
        $order->shipment()->create([
            'courier_name' => 'SiCepat', 'service_name' => null, 'tracking_number' => 'SC-0001',
            'shipping_cost' => '9000.00', 'dispatched_at' => '2026-11-03 10:00:00', 'delivered_at' => '2026-11-05 16:30:00',
        ]);
        $this->event($order, 'awaiting_confirmation', '2026-11-01 08:00:00', ['actor_type' => 'customer']);
        $this->event($order, 'shipped', '2026-11-03 10:00:00', ['metadata' => json_encode(['internal' => 'catatan-audit-staf'])]);
        $this->event($order, 'shipped', '2026-11-04 11:00:00', ['event_type' => 'tracking_updated', 'note' => 'Nomor resi diperbarui.']);
        $this->event($order, 'delivered', '2026-11-05 16:30:00');
        $iso = fn (string $at): string => Carbon::parse($at)->toIso8601String();

        $response = $this->bearer($token)->getJson(self::URL.'/'.$order->order_number.'/tracking');

        $response->assertOk()->assertExactJson(['data' => [
            'order_number' => $order->order_number,
            'status' => 'delivered',
            'fulfilment_method' => 'courier',
            'updated_by' => 'store',
            'shipment' => [
                'courier_name' => 'SiCepat', 'service_name' => null, 'tracking_number' => 'SC-0001', 'tracking_url' => null,
                'dispatched_at' => $iso('2026-11-03 10:00:00'), 'delivered_at' => $iso('2026-11-05 16:30:00'), 'shipping_cost' => '9000.00',
            ],
            'timeline' => [
                ['status' => 'delivered', 'event_type' => 'status_changed', 'note' => null, 'occurred_at' => $iso('2026-11-05 16:30:00')],
                ['status' => 'shipped', 'event_type' => 'tracking_updated', 'note' => 'Nomor resi diperbarui.', 'occurred_at' => $iso('2026-11-04 11:00:00')],
                ['status' => 'shipped', 'event_type' => 'status_changed', 'note' => null, 'occurred_at' => $iso('2026-11-03 10:00:00')],
                ['status' => 'awaiting_confirmation', 'event_type' => 'status_changed', 'note' => null, 'occurred_at' => $iso('2026-11-01 08:00:00')],
            ],
        ]]);
        $this->assertStringNotContainsString('catatan-audit-staf', $response->getContent());
    }

    public function test_tracking_of_a_pickup_order_has_no_shipment(): void
    {
        Setting::updateOrCreate(['key' => 'customer_commerce_pickup_instructions'], ['value' => 'Ambil di booth A12.']);
        [$account, $token] = $this->customerWithToken();
        $order = $this->orderFor($account, ['status' => 'ready_for_pickup']);
        $this->event($order, 'ready_for_pickup', '2026-11-03 10:00:00');

        $this->bearer($token)->getJson(self::URL.'/'.$order->order_number.'/tracking')->assertOk()
            ->assertJsonPath('data.fulfilment_method', 'pickup')
            ->assertJsonPath('data.updated_by', 'store')
            ->assertJsonPath('data.shipment', null)
            ->assertJsonCount(1, 'data.timeline');

        $this->bearer($token)->getJson(self::URL.'/'.$order->order_number)->assertOk()
            ->assertJsonPath('data.instructions', 'Ambil di booth A12.')
            ->assertJsonPath('data.shipping_address', null)
            ->assertJsonPath('data.shipping_cost_pending', false);
    }

    public function test_the_shipping_cost_is_pending_only_while_a_courier_order_awaits_confirmation(): void
    {
        [$account, $token] = $this->customerWithToken();
        $awaiting = $this->orderFor($account, [], courier: true);
        $confirmed = $this->orderFor($account, ['status' => 'confirmed'], courier: true);
        $cancelled = $this->orderFor($account, ['status' => 'cancelled', 'cancel_reason' => 'Stok habis.'], courier: true);

        $this->bearer($token)->getJson(self::URL.'/'.$awaiting->order_number)->assertJsonPath('data.shipping_cost_pending', true)->assertJsonPath('data.instructions', null);
        $this->bearer($token)->getJson(self::URL.'/'.$confirmed->order_number)->assertJsonPath('data.shipping_cost_pending', false);
        $this->bearer($token)->getJson(self::URL.'/'.$cancelled->order_number)
            ->assertJsonPath('data.shipping_cost_pending', false)
            ->assertJsonPath('data.cancel_reason', 'Stok habis.');
    }

    public function test_the_tracking_url_comes_only_from_an_allow_listed_https_template(): void
    {
        [$account, $token] = $this->customerWithToken();
        $order = $this->orderFor($account, ['status' => 'shipped'], courier: true);
        $shipment = $order->shipment()->create([
            'courier_name' => 'JNE', 'tracking_number' => 'AB 12/3?x=1&y', 'shipping_cost' => '0.00', 'dispatched_at' => now(),
        ]);
        $url = fn () => $this->bearer($token)->getJson(self::URL.'/'.$order->order_number.'/tracking')->assertOk()->json('data.shipment.tracking_url');

        // Bawaan: tidak ada templat, jadi tidak ada tautan.
        $this->assertNull($url());

        // Templat sah: nomor resi di-URL-encode, tidak pernah disisipkan mentah.
        config(['customer_commerce.tracking_url_templates' => ['JNE' => 'https://lacak.example.test/resi/{tracking_number}?src=app']]);
        $this->assertSame('https://lacak.example.test/resi/AB%2012%2F3%3Fx%3D1%26y?src=app', $url());
        $this->assertSame($url(), $this->bearer($token)->getJson(self::URL.'/'.$order->order_number)->json('data.shipment.tracking_url'));

        // Templat yang tidak aman diabaikan: bukan https, atau tanpa penanda nomor resi.
        foreach (['http://lacak.example.test/{tracking_number}', 'javascript:alert({tracking_number})', 'https://lacak.example.test/tanpa-penanda', '', null, ['x']] as $bad) {
            config(['customer_commerce.tracking_url_templates' => ['JNE' => $bad]]);
            $this->assertNull($url());
        }

        // Kurir lain tidak meminjam templat JNE; tanpa nomor resi tidak ada tautan.
        config(['customer_commerce.tracking_url_templates' => ['JNE' => 'https://lacak.example.test/{tracking_number}']]);
        CustomerOrderShipment::whereKey($shipment->id)->update(['courier_name' => 'SiCepat']);
        $this->assertNull($url());
        CustomerOrderShipment::whereKey($shipment->id)->update(['courier_name' => 'JNE', 'tracking_number' => null]);
        $this->assertNull($url());
    }

    public function test_another_accounts_order_is_a_404_identical_to_an_unknown_number(): void
    {
        [$owner] = $this->customerWithToken();
        [, $intruderToken] = $this->customerWithToken();
        $order = $this->orderFor($owner, ['contact_name' => 'Pemilik Asli', 'contact_email' => 'pemilik@example.test'], courier: true);
        CustomerOrderItem::factory()->create(['customer_order_id' => $order->id, 'name_snapshot' => 'Barang Rahasia']);
        $unknown = 'CO-0000000000';
        $this->assertDatabaseMissing('customer_orders', ['order_number' => $unknown]);

        $body = function ($response): array {
            $response->assertNotFound()->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('code', 'not_found');
            foreach (['Pemilik Asli', 'pemilik@example.test', 'Barang Rahasia', 'Penerima Uji', 'CustomerOrder'] as $leak) {
                $this->assertStringNotContainsString($leak, $response->getContent());
            }

            return collect($response->json())->except('request_id')->all();
        };

        foreach (['', '/tracking'] as $suffix) {
            $foreign = $body($this->bearer($intruderToken)->getJson(self::URL.'/'.$order->order_number.$suffix));
            $missing = $body($this->bearer($intruderToken)->getJson(self::URL.'/'.$unknown.$suffix));
            $this->assertSame($missing, $foreign);
        }

        // Hanya bentuk kanonis nomor yang dikenali: pemiliknya sendiri pun tidak bisa memakai huruf kecil
        // (kolasi MySQL tidak peka huruf besar/kecil; pola rute yang menolaknya).
        $ownerToken = $this->issueCustomerToken($owner);
        $this->bearer($ownerToken)->getJson(self::URL.'/'.$order->order_number)->assertOk();
        $this->bearer($ownerToken)->getJson(self::URL.'/'.strtolower($order->order_number))->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->bearer($ownerToken)->getJson(self::URL.'/'.strtolower($order->order_number).'/tracking')->assertNotFound();

        // Nomor yang salah bentuk (huruf kecil, id numerik staf) berhenti di rute dengan 404 yang sama.
        $this->bearer($intruderToken)->getJson(self::URL.'/'.strtolower($order->order_number))->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->bearer($intruderToken)->getJson(self::URL.'/'.$order->id)->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->bearer($intruderToken)->getJson(self::URL.'/'.$order->id.'/tracking')->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    public function test_reading_orders_requires_a_customer_token_and_refuses_a_staff_token(): void
    {
        [$account] = $this->customerWithToken();
        $order = $this->orderFor($account);
        $staffToken = $this->issueStaffToken(User::factory()->create(['role' => 'owner']));

        foreach ([self::URL, self::URL.'/'.$order->order_number, self::URL.'/'.$order->order_number.'/tracking'] as $url) {
            $this->withoutBearer()->getJson($url)->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
            $this->bearer($staffToken)->getJson($url)->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        }
    }

    public function test_the_customer_has_no_route_that_mutates_an_order(): void
    {
        [$account, $token] = $this->customerWithToken();
        $order = $this->orderFor($account);
        $url = self::URL.'/'.$order->order_number;

        foreach (['putJson', 'patchJson', 'deleteJson'] as $verb) {
            $this->bearer($token)->{$verb}($url, ['status' => 'cancelled'])->assertStatus(405);
        }
        foreach (['cancel', 'confirm', 'status'] as $action) {
            $this->bearer($token)->postJson("{$url}/{$action}", ['status' => 'cancelled'])->assertNotFound();
        }

        $this->assertSame('awaiting_confirmation', $order->fresh()->status);
    }

    public function test_list_detail_and_tracking_still_work_while_the_feature_is_off(): void
    {
        [$account, $token] = $this->customerWithToken();
        $order = $this->orderFor($account, ['status' => 'confirmed']);
        $this->event($order, 'confirmed', '2026-11-02 09:00:00');
        $this->disableCustomerCommerce();

        $this->bearer($token)->getJson(self::URL)->assertOk()->assertJsonPath('data.0.order_number', $order->order_number);
        $this->bearer($token)->getJson(self::URL.'/'.$order->order_number)->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->bearer($token)->getJson(self::URL.'/'.$order->order_number.'/tracking')->assertOk()->assertJsonPath('data.updated_by', 'store');
    }
}
