<?php

namespace Tests\Feature\CustomerCommerce;

use App\Exceptions\CustomerCommerceException;
use App\Models\ActivityLog;
use App\Models\CustomerOrder;
use App\Models\Event;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CustomerCommerce\CustomerOrderTransitionService;
use App\Services\StockService;
use App\Services\StockSync\StockSyncPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §9) — stok pada fulfilment pesanan pelanggan.
 *
 * Yang dijaga: stok TIDAK bergerak saat checkout; dikurangi tepat sekali saat
 * staf mengonfirmasi, hanya lewat StockService (pergerakan `sale` dengan
 * referensi item pesanan, stock_before/after konsisten); konfirmasi yang
 * kekurangan stok atau kehilangan varian ditolak 409 tanpa menulis apa pun;
 * pembatalan mengembalikan stok tepat sekali bila sudah dipotong, dan tidak
 * menulis pergerakan bila belum; dan pergerakan itu tidak merusak layar Stok,
 * sinkron stok, maupun laporan penjualan POS.
 */
class StaffFulfilmentStockTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    /** @var array<string,mixed> */
    private array $world;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCustomerCommerce();
        $this->world = $this->buildStorefrontWorld();
        // Menu `stock` hanya untuk membaca GET /stock/movements di test ini.
        $this->staff = $this->fulfilmentStaff(null, ['stock']);
    }

    private function act(CustomerOrder $order, string $action, array $body = []): TestResponse
    {
        return $this->asStaff($this->staff)->postJson("/api/v1/customer-orders/{$order->id}/{$action}", $body);
    }

    /** Pergerakan stok yang merujuk item pesanan ini, urut penulisan. */
    private function movementsOf(CustomerOrder $order)
    {
        return DB::table('stock_movements')
            ->where('reference_type', 'customer_order_item')
            ->whereIn('reference_id', $order->items()->pluck('id'))
            ->orderBy('id')->get();
    }

    /** Buku besar jujur: jumlah seluruh pergerakan sebuah varian = stoknya sekarang. */
    private function assertLedgerMatchesStock(ProductVariant $variant): void
    {
        $this->assertSame(
            (int) DB::table('stock_movements')->where('variant_id', $variant->id)->sum('qty_change'),
            (int) $variant->fresh()->current_stock,
            "Buku besar {$variant->sku} tidak cocok dengan current_stock.",
        );
    }

    // ------------------------------------------------------------------
    // Konfirmasi
    // ------------------------------------------------------------------

    public function test_confirm_deducts_stock_through_stock_movements(): void
    {
        [, $token] = $this->customerWithToken();
        $order = $this->placeOrderThroughCheckout($token, [[$this->world['variantB']->id, 1], [$this->world['variantA']->id, 2]]);

        // Checkout sendiri tidak menggerakkan stok.
        $this->assertCount(0, $this->movementsOf($order));
        $this->assertSame(10, $this->world['variantA']->fresh()->current_stock);

        $this->act($order, 'confirm')->assertOk();

        $items = $order->items()->get()->keyBy('variant_id');
        $movements = $this->movementsOf($order);
        $this->assertCount(2, $movements);

        // Ditulis urut id varian (urutan kunci), bukan urutan baris keranjang.
        $this->assertSame([$this->world['variantA']->id, $this->world['variantB']->id], $movements->pluck('variant_id')->all());

        $a = $movements[0];
        $this->assertSame('sale', $a->type);
        $this->assertSame(-2, $a->qty_change);
        $this->assertSame(10, $a->stock_before);
        $this->assertSame(8, $a->stock_after);
        $this->assertSame($items[$this->world['variantA']->id]->id, $a->reference_id);
        $this->assertSame($this->staff->id, $a->user_id);
        $this->assertSame('live', $a->data_mode);

        $b = $movements[1];
        $this->assertSame(-1, $b->qty_change);
        $this->assertSame(5, $b->stock_before);
        $this->assertSame(4, $b->stock_after);
        $this->assertSame($items[$this->world['variantB']->id]->id, $b->reference_id);

        $this->assertSame(8, $this->world['variantA']->fresh()->current_stock);
        $this->assertSame(4, $this->world['variantB']->fresh()->current_stock);
        $this->assertLedgerMatchesStock($this->world['variantA']);
        $this->assertLedgerMatchesStock($this->world['variantB']);

        // Kemas / siap / diambil tidak menyentuh stok lagi.
        $this->act($order, 'pack')->assertOk();
        $this->act($order, 'ready')->assertOk();
        $this->act($order, 'pickup')->assertOk();
        $this->assertCount(2, $this->movementsOf($order));
        $this->assertSame(8, $this->world['variantA']->fresh()->current_stock);
    }

    public function test_the_stock_ledger_shows_the_customer_order_number_for_a_confirmed_order(): void
    {
        [, $token] = $this->customerWithToken();
        $order = $this->placeOrderThroughCheckout($token, [[$this->world['variantA']->id, 2]]);
        $this->act($order, 'confirm')->assertOk();

        $movement = $this->movementsOf($order)->sole();
        $row = collect($this->asStaff($this->staff)->getJson('/api/v1/stock/movements?per_page=100')->assertOk()->json('data'))
            ->firstWhere('id', $movement->id);

        $this->assertSame('sale', $row['type']);
        $this->assertSame(-2, $row['qty_change']);
        $this->assertSame(['type' => 'customer_order', 'id' => $order->id, 'number' => $order->order_number], $row['reference']);
        $this->assertSame($this->staff->name, $row['user_name']);
    }

    public function test_two_awaiting_orders_for_the_last_unit_only_the_first_confirmation_wins(): void
    {
        [, $variant] = $this->makeStorefrontProduct(['name' => 'Pin Edisi Terakhir'], price: 12000, stock: 1);
        [, $tokenOne] = $this->customerWithToken();
        [, $tokenTwo] = $this->customerWithToken();

        // Keduanya lolos checkout: checkout tidak mencadangkan stok.
        $first = $this->placeOrderThroughCheckout($tokenOne, [[$variant->id, 1]]);
        $second = $this->placeOrderThroughCheckout($tokenTwo, [[$variant->id, 1]]);
        $this->assertSame(1, $variant->fresh()->current_stock);

        $this->act($first, 'confirm')->assertOk();
        $this->assertSame(0, $variant->fresh()->current_stock);

        $secondRow = json_encode(DB::table('customer_orders')->where('id', $second->id)->first());
        $item = $second->items()->sole();

        $response = $this->act($second, 'confirm', ['note' => 'coba konfirmasi']);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('errors.items', [[
                'item_id' => $item->id,
                'variant_id' => $variant->id,
                'sku' => $variant->sku,
                'name' => $item->name_snapshot,
                'reason' => 'insufficient_stock',
                'requested' => 1,
                'available' => 0,
            ]]);

        // Pesanan kedua tidak berubah sama sekali dan tidak ada yang tertulis untuknya.
        $this->assertSame($secondRow, json_encode(DB::table('customer_orders')->where('id', $second->id)->first()));
        $this->assertCount(0, $this->movementsOf($second));
        $this->assertSame(1, DB::table('customer_order_status_events')->where('customer_order_id', $second->id)->count());
        $this->assertSame(0, ActivityLog::where('entity_type', 'CustomerOrder')->where('entity_id', $second->id)->where('action', 'customer_order_confirm')->count());
        $this->assertSame(0, $variant->fresh()->current_stock);
        $this->assertLedgerMatchesStock($variant);

        // Staf masih bisa membatalkannya; karena stoknya belum pernah dipotong, tidak ada yang dikembalikan.
        $this->act($second, 'cancel', ['reason' => 'Stok habis'])->assertOk();
        $this->assertCount(0, $this->movementsOf($second));
        $this->assertSame(0, $variant->fresh()->current_stock);
    }

    public function test_one_short_line_refuses_the_whole_confirmation_and_deducts_nothing(): void
    {
        [, $token] = $this->customerWithToken();
        $order = $this->placeOrderThroughCheckout($token, [[$this->world['variantA']->id, 2], [$this->world['variantB']->id, 5]]);

        // Setelah checkout, stok Biru turun (terjual di booth) sehingga hanya baris itu yang kurang.
        app(StockService::class)->applyMovement($this->world['variantB']->fresh(), 'adjustment', -2, null, null, 'terjual di booth');
        $movementsBefore = DB::table('stock_movements')->count();

        $response = $this->act($order, 'confirm');

        $response->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->assertCount(1, $response->json('errors.items'));
        $response->assertJsonPath('errors.items.0.variant_id', $this->world['variantB']->id)
            ->assertJsonPath('errors.items.0.requested', 5)
            ->assertJsonPath('errors.items.0.available', 3);

        // Baris yang stoknya CUKUP juga tidak dipotong: semua atau tidak sama sekali.
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertSame(10, $this->world['variantA']->fresh()->current_stock);
        $this->assertSame(3, $this->world['variantB']->fresh()->current_stock);
        $this->assertSame('awaiting_confirmation', $order->fresh()->status);
        $this->assertNull($order->fresh()->stock_deducted_at);
        $this->assertNull($order->fresh()->confirmed_at);
    }

    public function test_a_variant_removed_after_checkout_blocks_confirmation_as_a_business_conflict(): void
    {
        [, $token] = $this->customerWithToken();
        $order = $this->placeOrderThroughCheckout($token, [[$this->world['variantA']->id, 1], [$this->world['variantB']->id, 1]]);

        // Disimulasikan langsung di basis data: jalur hapus resmi menolak varian yang pernah dipesan.
        DB::table('product_variants')->where('id', $this->world['variantB']->id)->update(['deleted_at' => now()]);
        $movementsBefore = DB::table('stock_movements')->count();

        $response = $this->act($order, 'confirm');

        $response->assertStatus(409)->assertJsonPath('code', 'variant_unavailable')
            ->assertJsonPath('errors.items.0.variant_id', $this->world['variantB']->id)
            ->assertJsonPath('errors.items.0.reason', 'variant_unavailable')
            ->assertJsonPath('errors.items.0.available', 0);
        $this->assertCount(1, $response->json('errors.items'));

        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertSame(10, $this->world['variantA']->fresh()->current_stock);
        $this->assertSame('awaiting_confirmation', $order->fresh()->status);

        // Detail staf menandai baris itu tidak tersedia, bukan gagal dimuat.
        $detail = $this->asStaff($this->staff)->getJson("/api/v1/customer-orders/{$order->id}")->assertOk();
        $stockByVariant = collect($detail->json('data.items'))->pluck('available_stock', 'variant_id');
        $this->assertSame(10, $stockByVariant[$this->world['variantA']->id]);
        $this->assertNull($stockByVariant[$this->world['variantB']->id]);
    }

    public function test_a_variant_of_the_other_data_mode_is_unavailable_too(): void
    {
        $order = $this->makeOrderInStatus('awaiting_confirmation', [[$this->world['variantA'], 1]]);
        DB::table('product_variants')->where('id', $this->world['variantA']->id)->update(['data_mode' => 'demo']);

        $this->act($order, 'confirm')->assertStatus(409)->assertJsonPath('code', 'variant_unavailable');

        $this->assertSame('awaiting_confirmation', $order->fresh()->status);
        $this->assertCount(0, $this->movementsOf($order));
    }

    public function test_a_variant_deactivated_or_unpublished_after_checkout_can_still_be_confirmed(): void
    {
        [, $token] = $this->customerWithToken();
        $order = $this->placeOrderThroughCheckout($token, [[$this->world['variantA']->id, 1], [$this->world['variantB']->id, 1]]);

        // Pesanan mendahului perubahan itu dan barangnya masih ada: staf yang memutuskan (konfirmasi atau batal).
        $this->world['variantA']->update(['is_active' => false]);
        $this->world['variantB']->forceFill(['published_at' => null])->save();

        $this->act($order, 'confirm')->assertOk()->assertJsonPath('data.status', 'confirmed');

        $this->assertSame(9, $this->world['variantA']->fresh()->current_stock);
        $this->assertSame(4, $this->world['variantB']->fresh()->current_stock);
    }

    public function test_duplicate_variant_lines_are_summed_before_the_stock_check(): void
    {
        // Checkout menolak varian ganda; baris ganda hanya mungkin dari data yang dibuat di luar checkout.
        [, $variant] = $this->makeStorefrontProduct(['name' => 'Gantungan Ganda'], stock: 3);
        $order = $this->makeOrderInStatus('awaiting_confirmation', [[$variant, 2], [$variant, 2]]);

        $this->act($order, 'confirm')->assertStatus(409)->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('errors.items.0.requested', 4)
            ->assertJsonPath('errors.items.0.available', 3);

        $this->assertSame(3, $variant->fresh()->current_stock);
        $this->assertCount(0, $this->movementsOf($order));
    }

    // ------------------------------------------------------------------
    // Pembatalan
    // ------------------------------------------------------------------

    public function test_cancel_after_confirm_restores_stock_exactly_once(): void
    {
        [, $token] = $this->customerWithToken();
        $order = $this->placeOrderThroughCheckout($token, [[$this->world['variantA']->id, 3], [$this->world['variantB']->id, 2]]);
        $this->act($order, 'confirm')->assertOk();
        $this->assertSame(7, $this->world['variantA']->fresh()->current_stock);
        $this->assertSame(3, $this->world['variantB']->fresh()->current_stock);

        $this->act($order, 'cancel', ['reason' => 'Pelanggan membatalkan lewat WhatsApp'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(10, $this->world['variantA']->fresh()->current_stock);
        $this->assertSame(5, $this->world['variantB']->fresh()->current_stock);

        $items = $order->items()->get()->keyBy('variant_id');
        $returns = $this->movementsOf($order)->where('type', 'return')->values();
        $this->assertCount(2, $returns);
        $this->assertSame(3, $returns[0]->qty_change);
        $this->assertSame(7, $returns[0]->stock_before);
        $this->assertSame(10, $returns[0]->stock_after);
        $this->assertSame($items[$this->world['variantA']->id]->id, $returns[0]->reference_id, 'Referensi sama dengan pengurangannya.');
        $this->assertSame($this->staff->id, $returns[0]->user_id);
        $this->assertSame(2, $returns[1]->qty_change);
        $this->assertSame($items[$this->world['variantB']->id]->id, $returns[1]->reference_id);

        $this->assertTrue(json_decode(DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->orderByDesc('id')->value('metadata'), true)['stock_restored']);

        // Batal ulang ditolak dan TIDAK mengembalikan stok untuk kedua kalinya.
        $movementsBefore = DB::table('stock_movements')->count();
        $this->act($order, 'cancel', ['reason' => 'Batal lagi'])->assertStatus(409)->assertJsonPath('code', 'invalid_transition');

        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertSame(10, $this->world['variantA']->fresh()->current_stock);
        $this->assertSame(5, $this->world['variantB']->fresh()->current_stock);
        $this->assertSame('Pelanggan membatalkan lewat WhatsApp', $order->fresh()->cancel_reason);
        $this->assertLedgerMatchesStock($this->world['variantA']);
        $this->assertLedgerMatchesStock($this->world['variantB']);

        // Kedua pergerakan tampil di layar Stok dengan nomor pesanan yang sama.
        $rows = collect($this->asStaff($this->staff)->getJson('/api/v1/stock/movements?per_page=100&variant_id='.$this->world['variantA']->id)->assertOk()->json('data'))
            ->whereIn('type', ['sale', 'return']);
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(['type' => 'customer_order', 'id' => $order->id, 'number' => $order->order_number], $row['reference']);
        }
    }

    public function test_cancel_restores_stock_from_packing_and_ready_for_pickup_too(): void
    {
        foreach (['packing', 'ready_for_pickup'] as $status) {
            [, $variant] = $this->makeStorefrontProduct(['name' => "Produk {$status}"], stock: 6);
            $order = $this->makeOrderInStatus($status, [[$variant, 2]]);
            $this->assertSame(4, $variant->fresh()->current_stock);

            $this->act($order, 'cancel', ['reason' => 'Dibatalkan toko'])->assertOk();

            $this->assertSame(6, $variant->fresh()->current_stock, $status);
            $this->assertSame(['sale', 'return'], $this->movementsOf($order)->pluck('type')->all(), $status);
            $this->assertLedgerMatchesStock($variant);
        }
    }

    public function test_cancel_before_confirm_writes_no_stock_movement(): void
    {
        [, $token] = $this->customerWithToken();
        $order = $this->placeOrderThroughCheckout($token, [[$this->world['variantA']->id, 2]]);
        $movementsBefore = DB::table('stock_movements')->count();

        $this->act($order, 'cancel', ['reason' => 'Pesanan ganda'])->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertSame(10, $this->world['variantA']->fresh()->current_stock);
        $this->assertNull($order->fresh()->stock_deducted_at);
        $this->assertFalse(json_decode(DB::table('customer_order_status_events')->where('customer_order_id', $order->id)->orderByDesc('id')->value('metadata'), true)['stock_restored']);
    }

    public function test_cancel_is_refused_without_side_effects_when_a_deducted_variant_is_gone(): void
    {
        $order = $this->makeOrderInStatus('confirmed', [[$this->world['variantA'], 2], [$this->world['variantB'], 1]]);
        DB::table('product_variants')->where('id', $this->world['variantB']->id)->update(['deleted_at' => now()]);
        $movementsBefore = DB::table('stock_movements')->count();

        $this->act($order, 'cancel', ['reason' => 'Batal'])->assertStatus(409)->assertJsonPath('code', 'variant_unavailable');

        // Tidak ada pengembalian sebagian: varian yang masih ada pun tidak disentuh.
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertSame(8, $this->world['variantA']->fresh()->current_stock);
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertNull($order->fresh()->cancel_reason);
    }

    // ------------------------------------------------------------------
    // Kunci baris ("tepat satu kali")
    // ------------------------------------------------------------------

    public function test_confirm_and_cancel_lock_the_order_row_first_then_the_variants_in_id_order(): void
    {
        [, $token] = $this->customerWithToken();
        // Keranjang sengaja dengan id varian MENURUN: urutan kunci tidak boleh mengikuti urutan baris.
        $this->assertGreaterThan($this->world['variantA']->id, $this->world['variantB']->id);
        $order = $this->placeOrderThroughCheckout($token, [[$this->world['variantB']->id, 1], [$this->world['variantA']->id, 1]]);

        $locks = [];
        DB::listen(function ($query) use (&$locks) {
            if (stripos($query->sql, 'for update') !== false) {
                $locks[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
            }
        });

        foreach (['confirm' => [], 'cancel' => ['reason' => 'Uji kunci']] as $action => $body) {
            $locks = [];
            $this->act($order, $action, $body)->assertOk();

            $this->assertStringContainsString('`customer_orders`', $locks[0]['sql'], "{$action}: baris pesanan dikunci lebih dulu");
            $this->assertStringContainsString('`product_variants`', $locks[1]['sql'], "{$action}: lalu seluruh varian sekaligus");
            $this->assertStringContainsString('order by `id` asc', $locks[1]['sql']);
            $this->assertContains($this->world['variantA']->id, $locks[1]['bindings']);
            $this->assertContains($this->world['variantB']->id, $locks[1]['bindings']);

            // Sesudahnya StockService mengunci per varian, menaik.
            $perVariant = array_values(array_filter(array_slice($locks, 2), fn (array $lock) => str_contains($lock['sql'], '`product_variants`')));
            $this->assertSame(
                [$this->world['variantA']->id, $this->world['variantB']->id],
                array_map(fn (array $lock) => $lock['bindings'][0], $perVariant),
                "{$action}: stok digerakkan urut id varian",
            );
        }
    }

    public function test_the_status_is_read_from_the_locked_row_not_from_a_stale_model(): void
    {
        $order = $this->makeOrderInStatus('confirmed', [[$this->world['variantA'], 2]]);
        $service = app(CustomerOrderTransitionService::class);

        // Dua "permintaan" memegang salinan pesanan yang sama-sama masih `confirmed`.
        $first = CustomerOrder::findOrFail($order->id);
        $stale = CustomerOrder::findOrFail($order->id);

        $service->cancel($first, $this->staff, 'Batal pertama');
        $this->assertSame(10, $this->world['variantA']->fresh()->current_stock);
        $this->assertSame('confirmed', $stale->status, 'Salinan kedua memang basi.');

        try {
            $service->cancel($stale, $this->staff, 'Batal kedua');
            $this->fail('Pembatalan kedua harus ditolak.');
        } catch (CustomerCommerceException $e) {
            $this->assertSame('invalid_transition', $e->reason);
            $this->assertSame(409, $e->status);
        }

        // Stok dikembalikan TEPAT satu kali, dan alasan pertama tidak tertimpa.
        $this->assertSame(10, $this->world['variantA']->fresh()->current_stock);
        $this->assertSame(1, $this->movementsOf($order)->where('type', 'return')->count());
        $this->assertSame('Batal pertama', $order->fresh()->cancel_reason);

        // Hal yang sama untuk konfirmasi ganda: stok tidak dipotong dua kali.
        $awaiting = $this->makeOrderInStatus('awaiting_confirmation', [[$this->world['variantB'], 1]]);
        $staleAwaiting = CustomerOrder::findOrFail($awaiting->id);
        $service->confirm(CustomerOrder::findOrFail($awaiting->id), $this->staff);

        try {
            $service->confirm($staleAwaiting, $this->staff);
            $this->fail('Konfirmasi kedua harus ditolak.');
        } catch (CustomerCommerceException $e) {
            $this->assertSame('invalid_transition', $e->reason);
        }

        $this->assertSame(4, $this->world['variantB']->fresh()->current_stock);
        $this->assertSame(1, $this->movementsOf($awaiting)->count());
    }

    // ------------------------------------------------------------------
    // Yang TIDAK boleh rusak
    // ------------------------------------------------------------------

    public function test_stock_sync_counts_a_confirmed_customer_order_like_any_other_sale(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        [, $kept] = $this->makeStorefrontProduct(['name' => 'Dikonfirmasi lalu stok diketik'], stock: 10);
        [, $cancelled] = $this->makeStorefrontProduct(['name' => 'Dikonfirmasi lalu dibatalkan'], stock: 10);
        [, $token] = $this->customerWithToken();

        $keptOrder = $this->placeOrderThroughCheckout($token, [[$kept->id, 2]]);
        $cancelledOrder = $this->placeOrderThroughCheckout($token, [[$cancelled->id, 3]]);
        $this->act($keptOrder, 'confirm')->assertOk();
        $this->act($cancelledOrder, 'confirm')->assertOk();
        $this->act($cancelledOrder, 'cancel', ['reason' => 'Batal'])->assertOk();

        // Pemilik lalu "mengetik" stok riil 12 untuk keduanya (jalur impor stok: adjustment sebesar selisihnya),
        // tanpa memperhitungkan pesanan tadi.
        foreach ([$kept, $cancelled] as $variant) {
            app(StockService::class)->applyMovement(
                $variant->fresh(), 'adjustment', 12 - $variant->fresh()->current_stock, 'MasterDataImport', null, 'Impor massal master data', $owner->id,
            );
        }

        $rows = collect(app(StockSyncPlanner::class)->plan()['rows'])->keyBy('variant_id');

        // Pengurangan pesanan pelanggan dihitung sebagai unit keluar…
        $this->assertSame(2, $rows[$kept->id]['sold']);
        $this->assertSame(2, $rows[$kept->id]['taken']);
        $this->assertSame(StockSyncPlanner::STATUS_CHANGE, $rows[$kept->id]['status']);

        // …dan pembatalannya menetralkannya (return), jadi tidak ada koreksi.
        $this->assertSame(3, $rows[$cancelled->id]['sold']);
        $this->assertSame(3, $rows[$cancelled->id]['returned']);
        $this->assertSame(0, $rows[$cancelled->id]['taken']);
        $this->assertSame(StockSyncPlanner::STATUS_NONE, $rows[$cancelled->id]['status']);
    }

    public function test_a_fulfilled_customer_order_does_not_appear_in_pos_sales_or_reports(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $event = Event::factory()->create(['status' => 'active']);
        [, $token] = $this->customerWithToken();
        $order = $this->placeOrderThroughCheckout($token, [[$this->world['variantA']->id, 2]]);
        foreach (['confirm', 'pack', 'ready', 'pickup'] as $action) {
            $this->act($order, $action)->assertOk();
        }

        // Tidak ada penjualan POS / pre-order / pembayaran yang lahir dari pesanan pelanggan.
        foreach (['orders', 'order_items', 'preorders', 'preorder_items', 'payments', 'shipments'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }

        $report = $this->asStaff($owner)->getJson("/api/v1/reports/sales?event_id={$event->id}")->assertOk();
        $this->assertEquals(0, $report->json('totals.order_count'));
        $this->assertSame([], $report->json('transactions'));

        // Satu-satunya jejak di luar tabel pesanan pelanggan adalah buku besar stok.
        $this->assertSame(['initial', 'sale'], StockMovement::where('variant_id', $this->world['variantA']->id)->orderBy('id')->pluck('type')->all());
    }
}
