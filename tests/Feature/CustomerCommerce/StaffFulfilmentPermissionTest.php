<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\ActivityLog;
use App\Models\Artist;
use App\Models\CustomerOrder;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\MenuActions;
use App\Support\MenuKeys;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §1, §2, §9) — SIAPA yang boleh menyentuh
 * fulfilment pesanan pelanggan:
 *  - menu `customer_orders` + satu aksi bernama per transisi (peran bawaan:
 *    hanya Owner dan Admin);
 *  - staf terikat seller ditolak di seluruh permukaan ini;
 *  - token PELANGGAN ditolak 401 di setiap rute staf, dan token STAF ditolak
 *    di rute pesanan pelanggan — diuji dengan token bearer sungguhan;
 *  - rute staf tetap bekerja saat saklar fitur MATI;
 *  - daftar dan aksi staf tidak pernah melintasi mode DEMO/LIVE.
 */
class StaffFulfilmentPermissionTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    /** Badan sah per aksi, supaya penolakan yang diuji adalah izinnya, bukan validasinya. */
    private const ACTIONS = [
        'confirm' => [],
        'pack' => [],
        'ready' => [],
        'pickup' => [],
        'ship' => ['courier_name' => 'JNE'],
        'deliver' => [],
        'cancel' => ['reason' => 'Uji izin'],
    ];

    /**
     * Sepuluh rute fulfilment staf untuk satu pesanan: [metode, uri, badan].
     *
     * @return list<array{0: string, 1: string, 2: array<string,mixed>}>
     */
    private function everyRoute(CustomerOrder $order): array
    {
        $routes = [
            ['GET', '/api/v1/customer-orders', []],
            ['GET', "/api/v1/customer-orders/{$order->id}", []],
            ['PATCH', "/api/v1/customer-orders/{$order->id}/shipment", ['tracking_number' => 'JNE-1']],
        ];

        foreach (self::ACTIONS as $action => $body) {
            $routes[] = ['POST', "/api/v1/customer-orders/{$order->id}/{$action}", $body];
        }

        return $routes;
    }

    private function rawOrder(CustomerOrder $order): string
    {
        return json_encode(DB::table('customer_orders')->where('id', $order->id)->first());
    }

    private function nothingWasWritten(CustomerOrder $order, string $rowBefore): void
    {
        $this->assertSame($rowBefore, $this->rawOrder($order));
        $this->assertSame(0, DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->count());
        $this->assertSame(0, DB::table('customer_order_shipments')->where('customer_order_id', $order->id)->count());
        $this->assertSame(0, DB::table('stock_movements')->where('reference_type', 'customer_order_item')->count());
        $this->assertSame(0, ActivityLog::where('entity_type', 'CustomerOrder')->count());
    }

    private function awaitingOrder(): CustomerOrder
    {
        [, $variant] = $this->makeStorefrontProduct(stock: 20);

        return $this->makeOrderInStatus('awaiting_confirmation', [[$variant, 1]]);
    }

    // ------------------------------------------------------------------
    // Registri dan peran bawaan
    // ------------------------------------------------------------------

    public function test_the_menu_and_its_named_actions_are_registered(): void
    {
        $this->assertSame('Pesanan Pelanggan', MenuKeys::ALL['customer_orders']);
        $this->assertSame(['confirm', 'pack', 'ready', 'pickup', 'ship', 'deliver', 'cancel'], MenuActions::for('customer_orders'));

        // Editor peran menerimanya dari GET /menu-keys dengan label per bahasa.
        $owner = User::factory()->create(['role' => 'owner']);
        $entry = collect($this->asStaff($owner)->getJson('/api/v1/menu-keys')->assertOk()->json('data'))->firstWhere('key', 'customer_orders');

        $this->assertSame('Pesanan Pelanggan', $entry['label']);
        $this->assertSame(['confirm', 'pack', 'ready', 'pickup', 'ship', 'deliver', 'cancel'], array_column($entry['actions'], 'key'));
        foreach ($entry['actions'] as $action) {
            $this->assertFalse($action['standard']);
            $this->assertStringNotContainsString('permissions.', $action['label']);
        }
    }

    public function test_only_owner_and_admin_get_the_menu_by_default(): void
    {
        $keysOf = fn (string $role) => json_decode(DB::table('roles')->where('name', $role)->value('menu_keys'), true);

        $this->assertContains('customer_orders', $keysOf('Owner'));
        $this->assertContains('customer_orders', $keysOf('Admin'));
        $this->assertNotContains('customer_orders', $keysOf('Kasir'));
        $this->assertNotContains('customer_orders', $keysOf('Inventory'));

        // NULL menu_actions = semua aksi: peran bawaan langsung bisa memenuhi pesanan.
        $this->assertNull(DB::table('roles')->where('name', 'Admin')->value('menu_actions'));

        $order = $this->awaitingOrder();
        $this->asStaff(User::factory()->create(['role' => 'admin']))
            ->postJson("/api/v1/customer-orders/{$order->id}/confirm")->assertOk();
        $this->asStaff(User::factory()->create(['role' => 'owner']))
            ->postJson("/api/v1/customer-orders/{$order->id}/pack")->assertOk();

        $this->asStaff(User::factory()->create(['role' => 'cashier']))->getJson('/api/v1/customer-orders')->assertForbidden();
        $this->asStaff(User::factory()->create(['role' => 'inventory']))
            ->postJson("/api/v1/customer-orders/{$order->id}/cancel", ['reason' => 'x'])
            ->assertForbidden()->assertJsonPath('code', 'action_not_allowed');
        $this->assertSame('packing', $order->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Izin menu dan aksi
    // ------------------------------------------------------------------

    public function test_a_role_without_the_menu_is_refused_on_every_route(): void
    {
        $order = $this->awaitingOrder();
        $rowBefore = $this->rawOrder($order);
        $stranger = User::factory()->create();
        $stranger->update(['role_id' => Role::factory()->create(['menu_keys' => ['dashboard', 'stock', 'products', 'customers', 'sales']])->id]);

        foreach ($this->everyRoute($order) as [$method, $uri, $body]) {
            $response = $this->asStaff($stranger->fresh())->json($method, $uri, $body);

            $this->assertSame(403, $response->status(), "{$method} {$uri}");
            if ($method !== 'GET') {
                $response->assertJsonPath('code', 'action_not_allowed')->assertJsonPath('menu', 'customer_orders');
            }
            $this->assertArrayNotHasKey('data', $response->json(), "{$method} {$uri} tidak boleh membocorkan data.");
        }

        $this->nothingWasWritten($order, $rowBefore);
    }

    public function test_a_role_with_only_confirm_cannot_perform_any_other_transition(): void
    {
        [, $variant] = $this->makeStorefrontProduct(stock: 20);
        $staff = $this->fulfilmentStaff(['confirm']);

        $awaiting = $this->makeOrderInStatus('awaiting_confirmation', [[$variant, 1]]);
        $this->asStaff($staff)->postJson("/api/v1/customer-orders/{$awaiting->id}/confirm")->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            // Tombol yang ditawarkan server mengikuti izin: pack/cancel sah untuk statusnya tetapi bukan untuk peran ini.
            ->assertJsonPath('data.available_actions', []);

        $packing = $this->makeOrderInStatus('packing', [[$variant, 1]], courier: true);
        $shipped = $this->makeOrderInStatus('shipped', [[$variant, 1]], courier: true);

        $this->asStaff($staff)->postJson("/api/v1/customer-orders/{$packing->id}/ship", ['courier_name' => 'JNE'])
            ->assertForbidden()
            ->assertJsonPath('code', 'action_not_allowed')
            ->assertJsonPath('menu', 'customer_orders')
            ->assertJsonPath('action', 'ship');
        // Mengubah resi memakai aksi `ship` juga.
        $this->asStaff($staff)->patchJson("/api/v1/customer-orders/{$shipped->id}/shipment", ['tracking_number' => 'JNE-2'])
            ->assertForbidden()->assertJsonPath('action', 'ship');

        foreach (['pack' => $awaiting, 'ready' => $packing, 'pickup' => $packing, 'deliver' => $shipped, 'cancel' => $packing] as $action => $order) {
            $this->asStaff($staff)->postJson("/api/v1/customer-orders/{$order->id}/{$action}", self::ACTIONS[$action])
                ->assertForbidden()->assertJsonPath('code', 'action_not_allowed')->assertJsonPath('action', $action);
        }

        $this->assertSame('confirmed', $awaiting->fresh()->status);
        $this->assertSame('packing', $packing->fresh()->status);
        $this->assertSame('shipped', $shipped->fresh()->status);
        $this->assertSame('JNE-0001', $shipped->fresh()->shipment->tracking_number);
        $this->assertSame(0, DB::table('customer_order_shipments')->where('customer_order_id', $packing->id)->count());

        // Baca tetap boleh (View implisit bagi pemegang menu).
        $this->asStaff($staff)->getJson('/api/v1/customer-orders')->assertOk();
        $this->asStaff($staff)->getJson("/api/v1/customer-orders/{$packing->id}")->assertOk()
            ->assertJsonPath('data.available_actions', [])
            ->assertJsonPath('data.can_update_shipment', false);
    }

    public function test_each_named_action_gates_exactly_its_own_route(): void
    {
        [, $variant] = $this->makeStorefrontProduct(stock: 100);
        // Status dan metode yang membuat tiap aksi SAH, supaya 200 berarti izinnya yang membuka.
        $setup = [
            'confirm' => ['awaiting_confirmation', false], 'pack' => ['confirmed', false], 'ready' => ['packing', false],
            'pickup' => ['ready_for_pickup', false], 'ship' => ['packing', true], 'deliver' => ['shipped', true], 'cancel' => ['confirmed', false],
        ];

        foreach ($setup as $allowed => [$status, $courier]) {
            $staff = $this->fulfilmentStaff([$allowed]);

            foreach (array_keys($setup) as $action) {
                [$actionStatus, $actionCourier] = $setup[$action];
                $order = $this->makeOrderInStatus($actionStatus, [[$variant, 1]], courier: $actionCourier);
                $response = $this->asStaff($staff)->postJson("/api/v1/customer-orders/{$order->id}/{$action}", self::ACTIONS[$action]);

                if ($action === $allowed) {
                    $response->assertOk();
                    $this->assertNotSame($actionStatus, $order->fresh()->status, "{$allowed} harus membuka {$action}");
                } else {
                    $response->assertForbidden()->assertJsonPath('code', 'action_not_allowed');
                    $this->assertSame($actionStatus, $order->fresh()->status, "{$allowed} tidak boleh membuka {$action}");
                }
            }
        }
    }

    public function test_a_view_only_role_can_read_but_not_change_anything(): void
    {
        $order = $this->awaitingOrder();
        $rowBefore = $this->rawOrder($order);
        $viewer = $this->fulfilmentStaff([]);

        $this->asStaff($viewer)->getJson('/api/v1/customer-orders')->assertOk()->assertJsonPath('meta.total', 1);
        $this->asStaff($viewer)->getJson("/api/v1/customer-orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.status', 'awaiting_confirmation')
            ->assertJsonPath('data.available_actions', []);

        foreach (self::ACTIONS as $action => $body) {
            $this->asStaff($viewer)->postJson("/api/v1/customer-orders/{$order->id}/{$action}", $body)
                ->assertForbidden()->assertJsonPath('code', 'action_not_allowed');
        }

        $this->nothingWasWritten($order, $rowBefore);
    }

    public function test_a_seller_scoped_user_is_refused_on_every_route(): void
    {
        $order = $this->awaitingOrder();
        $rowBefore = $this->rawOrder($order);
        // Peran Admin memegang menu dan semua aksinya; yang menolak adalah ikatan seller-nya.
        $sellerUser = User::factory()->create(['role' => 'admin', 'artist_id' => Artist::factory()->create()->id]);

        foreach ($this->everyRoute($order) as [$method, $uri, $body]) {
            $this->asStaff($sellerUser)->json($method, $uri, $body)
                ->assertForbidden()
                ->assertJsonPath('code', 'seller_scope_forbidden');
        }

        $this->nothingWasWritten($order, $rowBefore);

        // Admin yang sama TANPA ikatan seller tidak ditolak.
        $this->asStaff(User::factory()->create(['role' => 'admin']))->getJson('/api/v1/customer-orders')->assertOk();
    }

    // ------------------------------------------------------------------
    // Batas token (token bearer sungguhan)
    // ------------------------------------------------------------------

    public function test_a_customer_token_is_refused_on_every_staff_fulfilment_route(): void
    {
        // Pemilik pesanan sendiri, dengan id akun yang SAMA dengan id seorang Owner: tetap tidak bisa.
        $owner = User::factory()->create(['role' => 'owner']);
        [$account, $token] = $this->customerWithToken(['id' => $owner->id]);
        $this->assertSame($owner->id, $account->id);

        [, $variant] = $this->makeStorefrontProduct(stock: 20);
        $order = $this->makeOrderInStatus('awaiting_confirmation', [[$variant, 1]], attrs: [
            'customer_account_id' => $account->id, 'customer_id' => $account->customer_id,
        ]);
        $rowBefore = $this->rawOrder($order);

        foreach ($this->everyRoute($order) as [$method, $uri, $body]) {
            $response = $this->bearer($token)->json($method, $uri, $body);

            $this->assertSame(401, $response->status(), "{$method} {$uri} harus 401 untuk token pelanggan, dapat {$response->status()}.");
            // Bentuk galat staf, bukan amplop problem+json pelanggan.
            $response->assertExactJson(['message' => 'Unauthenticated.']);
        }

        $this->nothingWasWritten($order, $rowBefore);
        $this->assertSame(20, $variant->fresh()->current_stock);

        // Token yang sama tetap sah untuk MEMBACA pesanannya sendiri di sisi pelanggan.
        $this->bearer($token)->getJson("/api/v1/customer/orders/{$order->order_number}")->assertOk()
            ->assertJsonPath('data.status', 'awaiting_confirmation');
    }

    public function test_requests_without_a_token_are_refused(): void
    {
        $order = $this->awaitingOrder();

        foreach ($this->everyRoute($order) as [$method, $uri, $body]) {
            $this->withoutBearer()->json($method, $uri, $body)->assertUnauthorized();
        }

        $this->assertSame('awaiting_confirmation', $order->fresh()->status);
    }

    public function test_a_staff_token_cannot_reach_customer_order_endpoints(): void
    {
        [$account] = $this->customerWithToken();
        [, $variant] = $this->makeStorefrontProduct(stock: 20);
        $order = $this->makeOrderInStatus('awaiting_confirmation', [[$variant, 1]], attrs: [
            'customer_account_id' => $account->id, 'customer_id' => $account->customer_id,
        ]);
        // Staf dengan seluruh izin fulfilment tetap bukan pelanggan.
        $staff = User::factory()->create(['role' => 'owner']);

        $attempts = [
            ['GET', '/api/v1/customer/orders', []],
            ['GET', "/api/v1/customer/orders/{$order->order_number}", []],
            ['GET', "/api/v1/customer/orders/{$order->order_number}/tracking", []],
            ['POST', '/api/v1/customer/orders/quote', ['fulfilment_method' => 'pickup', 'items' => [['variant_id' => $variant->id, 'qty' => 1]]]],
            ['POST', '/api/v1/customer/orders', ['fulfilment_method' => 'pickup', 'items' => [['variant_id' => $variant->id, 'qty' => 1]], 'expected_total' => '10000.00']],
        ];

        foreach ($attempts as [$method, $uri, $body]) {
            $response = $this->asStaff($staff)->withHeader('Idempotency-Key', $this->newIdempotencyKey())->json($method, $uri, $body);

            $this->assertSame(401, $response->status(), "{$method} {$uri}");
            $response->assertJsonPath('code', 'unauthenticated');
            $this->assertArrayNotHasKey('data', $response->json());
        }

        $this->assertSame(1, DB::table('customer_orders')->count(), 'Staf tidak bisa membuat pesanan atas nama pelanggan.');
    }

    // ------------------------------------------------------------------
    // Saklar fitur
    // ------------------------------------------------------------------

    public function test_staff_fulfilment_keeps_working_when_the_feature_flag_is_off(): void
    {
        [, $token] = $this->customerWithToken();
        [, $variant] = $this->makeStorefrontProduct(stock: 20);
        $pickup = $this->placeOrderThroughCheckout($token, [[$variant->id, 2]]);
        $toCancel = $this->placeOrderThroughCheckout($token, [[$variant->id, 1]]);
        $staff = $this->fulfilmentStaff();

        $this->disableCustomerCommerce();
        // Bukti saklarnya benar-benar mati untuk pelanggan.
        $this->withoutBearer()->getJson('/api/v1/storefront/products')->assertStatus(503);

        $this->asStaff($staff)->getJson('/api/v1/customer-orders')->assertOk()->assertJsonPath('meta.total', 2);
        $this->asStaff($staff)->getJson("/api/v1/customer-orders/{$pickup->id}")->assertOk();
        foreach (['confirm', 'pack', 'ready', 'pickup'] as $action) {
            $this->asStaff($staff)->postJson("/api/v1/customer-orders/{$pickup->id}/{$action}")->assertOk();
        }
        $this->asStaff($staff)->postJson("/api/v1/customer-orders/{$toCancel->id}/cancel", ['reason' => 'Toko online ditutup'])->assertOk();

        $this->assertSame('picked_up', $pickup->fresh()->status);
        $this->assertSame('cancelled', $toCancel->fresh()->status);
        $this->assertSame(18, $variant->fresh()->current_stock);

        // Pelanggan masih bisa mengikuti pesanannya saat fitur mati.
        $this->bearer($token)->getJson("/api/v1/customer/orders/{$pickup->order_number}/tracking")->assertOk()
            ->assertJsonPath('data.status', 'picked_up');
    }

    public function test_courier_fulfilment_also_works_with_the_flag_off(): void
    {
        [, $variant] = $this->makeStorefrontProduct(stock: 20);
        $order = $this->makeOrderInStatus('packing', [[$variant, 1]], courier: true);
        $staff = $this->fulfilmentStaff();
        $this->disableCustomerCommerce();

        $this->asStaff($staff)->postJson("/api/v1/customer-orders/{$order->id}/ship", ['courier_name' => 'J&T'])->assertOk();
        $this->asStaff($staff)->patchJson("/api/v1/customer-orders/{$order->id}/shipment", ['tracking_number' => 'JT-77'])->assertOk();
        $this->asStaff($staff)->postJson("/api/v1/customer-orders/{$order->id}/deliver")->assertOk();

        $this->assertSame('delivered', $order->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Mode data
    // ------------------------------------------------------------------

    public function test_the_staff_list_and_actions_never_cross_the_data_mode(): void
    {
        [, $variant] = $this->makeStorefrontProduct(stock: 20);
        $live = $this->makeOrderInStatus('awaiting_confirmation', [[$variant, 1]]);
        $demo = ModeGate::runAs('demo', fn () => CustomerOrder::factory()->create(['contact_name' => 'Pesanan Demo']));
        $this->assertSame('demo', DB::table('customer_orders')->where('id', $demo->id)->value('data_mode'));
        $demoRow = $this->rawOrder($demo);
        $staff = $this->fulfilmentStaff();

        // Mode aktif LIVE: hanya pesanan LIVE yang ada.
        $list = $this->asStaff($staff)->getJson('/api/v1/customer-orders')->assertOk();
        $this->assertSame([$live->id], array_column($list->json('data'), 'id'));
        $list->assertJsonPath('meta.total', 1);

        foreach ($this->everyRoute($demo) as [$method, $uri, $body]) {
            if ($uri === '/api/v1/customer-orders') {
                continue;
            }
            $this->asStaff($staff)->json($method, $uri, $body)->assertNotFound();
        }
        $this->assertSame($demoRow, $this->rawOrder($demo));
        $this->assertSame(0, DB::table('customer_order_status_events')->where('customer_order_id', $demo->id)->count());

        // Pencarian pun tidak menemukannya.
        $this->asStaff($staff)->getJson('/api/v1/customer-orders?q=Pesanan+Demo')->assertOk()->assertJsonPath('meta.total', 0);
        $this->asStaff($staff)->getJson('/api/v1/customer-orders?q='.$demo->order_number)->assertOk()->assertJsonPath('meta.total', 0);

        // Mode aktif DEMO: kebalikannya.
        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'demo']);

        $list = $this->asStaff($staff)->getJson('/api/v1/customer-orders')->assertOk();
        $this->assertSame([$demo->id], array_column($list->json('data'), 'id'));
        $this->asStaff($staff)->getJson("/api/v1/customer-orders/{$live->id}")->assertNotFound();
        $this->asStaff($staff)->postJson("/api/v1/customer-orders/{$live->id}/confirm")->assertNotFound();
        $this->assertSame('awaiting_confirmation', DB::table('customer_orders')->where('id', $live->id)->value('status'));
        $this->assertSame(20, (int) DB::table('product_variants')->where('id', $variant->id)->value('current_stock'));
    }

    // ------------------------------------------------------------------
    // Daftar
    // ------------------------------------------------------------------

    public function test_the_list_is_newest_first_with_filters_search_and_pagination(): void
    {
        [, $variant] = $this->makeStorefrontProduct(['name' => 'Poster A3'], stock: 30);
        $old = $this->makeOrderInStatus('awaiting_confirmation', [[$variant, 2]], attrs: ['contact_name' => 'Budi Santoso', 'created_at' => now()->subDays(3)]);
        $middle = $this->makeOrderInStatus('confirmed', [[$variant, 1]], courier: true, attrs: ['contact_name' => 'Citra 100%_Asli', 'created_at' => now()->subDays(2)]);
        $new = $this->makeOrderInStatus('awaiting_confirmation', [[$variant, 1]], courier: true, attrs: ['contact_name' => 'Dewi Lestari', 'contact_phone' => '081234500000', 'total' => '10000.00', 'created_at' => now()->subDay()]);
        $staff = $this->fulfilmentStaff();
        $ids = fn ($response) => array_column($response->json('data'), 'id');

        $all = $this->asStaff($staff)->getJson('/api/v1/customer-orders')->assertOk();
        $this->assertSame([$new->id, $middle->id, $old->id], $ids($all));
        $all->assertJsonPath('meta', ['current_page' => 1, 'per_page' => 25, 'total' => 3, 'last_page' => 1]);

        $all->assertJsonPath('data.0', [
            'id' => $new->id,
            'order_number' => $new->order_number,
            'status' => 'awaiting_confirmation',
            'fulfilment_method' => 'courier',
            'contact_name' => 'Dewi Lestari',
            'contact_phone' => '081234500000',
            'total' => '10000.00',
            'shipping_cost_pending' => true,
            'item_count' => 1,
            'first_item_name' => 'Produk Uji — Standard',
            'created_at' => $new->created_at->toIso8601String(),
            'updated_at' => $new->updated_at->toIso8601String(),
        ]);

        $this->assertSame([$new->id, $old->id], $ids($this->asStaff($staff)->getJson('/api/v1/customer-orders?status=awaiting_confirmation')));
        $this->assertSame([$middle->id], $ids($this->asStaff($staff)->getJson('/api/v1/customer-orders?status=confirmed')));
        $this->assertSame([$new->id, $middle->id], $ids($this->asStaff($staff)->getJson('/api/v1/customer-orders?fulfilment_method=courier')));
        $this->assertSame([], $ids($this->asStaff($staff)->getJson('/api/v1/customer-orders?status=shipped')));

        // Cari berdasarkan nomor pesanan (sebagian) dan nama pemesan.
        $this->assertSame([$old->id], $ids($this->asStaff($staff)->getJson('/api/v1/customer-orders?q='.substr($old->order_number, 3))));
        $this->assertSame([$old->id], $ids($this->asStaff($staff)->getJson('/api/v1/customer-orders?q=santoso')));
        // `%` dan `_` adalah huruf biasa, bukan wildcard.
        $this->assertSame([$middle->id], $ids($this->asStaff($staff)->getJson('/api/v1/customer-orders?q='.urlencode('%'))));
        $this->assertSame([$middle->id], $ids($this->asStaff($staff)->getJson('/api/v1/customer-orders?q='.urlencode('_'))));
        $this->assertSame([$middle->id], $ids($this->asStaff($staff)->getJson('/api/v1/customer-orders?q='.urlencode('100%_A'))));
        $this->assertSame([], $ids($this->asStaff($staff)->getJson('/api/v1/customer-orders?q='.urlencode('B_di'))));

        $page2 = $this->asStaff($staff)->getJson('/api/v1/customer-orders?per_page=2&page=2')->assertOk();
        $this->assertSame([$old->id], $ids($page2));
        $page2->assertJsonPath('meta', ['current_page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2]);

        foreach (['status=dikirim', 'fulfilment_method=drone', 'per_page=0', 'per_page=101', 'page=0', 'q='.str_repeat('x', 101)] as $query) {
            $this->asStaff($staff)->getJson("/api/v1/customer-orders?{$query}")->assertStatus(422);
        }
    }

    public function test_the_list_does_not_run_a_query_per_order(): void
    {
        [, $variant] = $this->makeStorefrontProduct(stock: 50);
        $staff = $this->fulfilmentStaff();
        $token = $this->issueStaffToken($staff);

        $count = function () use ($token): int {
            // Sanctum menulis `last_used_at` (presisi detik) hanya bila nilainya berubah. Tanpa memajukan jam,
            // permintaan yang jatuh di detik yang sama dengan pemanasan lebih murah SATU query daripada yang
            // jatuh di detik berikutnya, dan test ini kedip (ditemukan saat suite penuh: 6 vs 7).
            $this->travel(2)->seconds();

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->bearer($token)->getJson('/api/v1/customer-orders?per_page=100')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->makeOrderInStatus('awaiting_confirmation', [[$variant, 1]]);
        $count(); // pemanasan: permintaan pertama mengisi cache pengaturan (mode data, lisensi)
        $one = $count();

        for ($i = 0; $i < 12; $i++) {
            $this->makeOrderInStatus('awaiting_confirmation', [[$variant, 1], [$variant, 1]]);
        }

        $this->assertSame($one, $count(), 'Jumlah query daftar tidak boleh tumbuh bersama jumlah pesanan.');
    }
}
