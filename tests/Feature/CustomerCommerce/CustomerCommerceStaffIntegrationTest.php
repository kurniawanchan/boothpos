<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockMovementReferences;
use App\Services\StockService;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §4, "Other integrations") — tiga titik di sisi
 * staf yang harus tahu tentang tabel baru supaya tidak merusaknya diam-diam:
 *  - menghapus kontak yang punya akun/pesanan pelanggan (akun jadi yatim),
 *  - menghapus varian yang hanya direferensikan pesanan pelanggan yang belum
 *    dikonfirmasi (belum ada pergerakan stok, jadi tampak "tanpa riwayat"),
 *  - layar Stok menampilkan nomor pesanan pelanggan untuk pergerakan stoknya.
 */
class CustomerCommerceStaffIntegrationTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    private function actingAsOwner(): User
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        return $owner;
    }

    // ------------------------------------------------------------------
    // Hapus kontak pelanggan
    // ------------------------------------------------------------------

    public function test_a_customer_with_an_app_account_cannot_be_deleted(): void
    {
        $account = $this->makeCustomerAccount();
        $this->actingAsOwner();

        $this->deleteJson("/api/v1/customers/{$account->customer_id}")
            ->assertStatus(409)
            ->assertJsonPath('message', __('master_data.customer_delete_has_customer_account'));

        $this->assertNotNull(Customer::find($account->customer_id), 'Kontak tidak boleh terhapus: akunnya akan yatim.');
        $this->assertDatabaseMissing('activity_logs', ['action' => 'deleted', 'entity_type' => 'Customer', 'entity_id' => $account->customer_id]);
    }

    public function test_a_customer_with_customer_orders_cannot_be_deleted(): void
    {
        $order = CustomerOrder::factory()->create();
        $this->actingAsOwner();

        $this->deleteJson("/api/v1/customers/{$order->customer_id}")->assertStatus(409);

        $this->assertNotNull(Customer::find($order->customer_id));
    }

    public function test_a_plain_staff_contact_can_still_be_deleted(): void
    {
        $customer = Customer::factory()->create();
        $this->actingAsOwner();

        $this->deleteJson("/api/v1/customers/{$customer->id}")->assertNoContent();

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    // ------------------------------------------------------------------
    // Hapus varian
    // ------------------------------------------------------------------

    public function test_a_variant_referenced_only_by_a_customer_order_is_deactivated_not_deleted(): void
    {
        // Pesanan belum dikonfirmasi: belum ada pergerakan stok, jadi satu-satunya "riwayat" adalah item pesanan.
        $variant = ProductVariant::factory()->create();
        CustomerOrderItem::factory()->create(['variant_id' => $variant->id, 'sku_snapshot' => $variant->sku]);
        $this->actingAsOwner();

        $this->deleteJson("/api/v1/variants/{$variant->id}")->assertSuccessful();

        $fresh = ProductVariant::withTrashed()->findOrFail($variant->id);
        $this->assertNull($fresh->deleted_at, 'Varian yang pernah dipesan pelanggan tidak boleh dihapus.');
        $this->assertFalse($fresh->is_active);
        $this->assertSame($variant->sku, $fresh->sku, 'SKU tidak boleh diganti penanda "#id".');
    }

    public function test_a_customer_order_of_the_other_data_mode_still_counts_as_history(): void
    {
        $variant = ProductVariant::factory()->create();
        $order = ModeGate::runAs('demo', fn () => CustomerOrder::factory()->create());
        CustomerOrderItem::factory()->create(['customer_order_id' => $order->id, 'variant_id' => $variant->id]);
        $this->actingAsOwner();

        $this->deleteJson("/api/v1/variants/{$variant->id}")->assertSuccessful();

        $this->assertNull(ProductVariant::withTrashed()->findOrFail($variant->id)->deleted_at);
    }

    public function test_a_variant_without_any_history_is_still_deleted(): void
    {
        $variant = ProductVariant::factory()->create();
        $this->actingAsOwner();

        $this->deleteJson("/api/v1/variants/{$variant->id}")->assertSuccessful();

        $this->assertSoftDeleted('product_variants', ['id' => $variant->id]);
    }

    // ------------------------------------------------------------------
    // Referensi pergerakan stok
    // ------------------------------------------------------------------

    /** @return array{0: CustomerOrder, 1: CustomerOrderItem, 2: ProductVariant} */
    private function orderWithStockedItem(): array
    {
        $variant = ProductVariant::factory()->create();
        app(StockService::class)->applyMovement($variant, 'initial', 10);
        $order = CustomerOrder::factory()->create();
        $item = CustomerOrderItem::factory()->create(['customer_order_id' => $order->id, 'variant_id' => $variant->id, 'qty' => 2]);

        return [$order, $item, $variant];
    }

    public function test_stock_movements_of_a_customer_order_resolve_to_its_order_number(): void
    {
        [$order, $item, $variant] = $this->orderWithStockedItem();
        $stock = app(StockService::class);

        // Konvensi fulfilment: id ITEM di kedua arah (konfirmasi = sale, pembatalan = return).
        $sale = $stock->applyMovement($variant->fresh(), 'sale', -2, 'customer_order_item', $item->id);
        $return = $stock->applyMovement($variant->fresh(), 'return', 2, 'customer_order_item', $item->id);

        $resolved = app(StockMovementReferences::class)->resolve(StockMovement::whereIn('id', [$sale->id, $return->id])->get());

        $expected = ['type' => 'customer_order', 'id' => $order->id, 'number' => $order->order_number];
        $this->assertSame($expected, $resolved[$sale->id]);
        $this->assertSame($expected, $resolved[$return->id]);
    }

    public function test_a_reference_to_an_item_of_another_variant_resolves_to_nothing(): void
    {
        [, $item] = $this->orderWithStockedItem();
        $other = ProductVariant::factory()->create();
        app(StockService::class)->applyMovement($other, 'initial', 5);

        // Pergerakan varian LAIN yang (keliru) menunjuk item ini: lebih baik tanpa referensi daripada nomor yang salah.
        $movement = app(StockService::class)->applyMovement($other->fresh(), 'sale', -1, 'customer_order_item', $item->id);

        $resolved = app(StockMovementReferences::class)->resolve(StockMovement::whereKey($movement->id)->get());

        $this->assertNull($resolved[$movement->id]);
    }

    public function test_a_reference_to_an_order_of_the_other_data_mode_resolves_to_nothing(): void
    {
        [$order, $item, $variant] = $this->orderWithStockedItem();
        $movement = app(StockService::class)->applyMovement($variant->fresh(), 'sale', -2, 'customer_order_item', $item->id);

        // Tabel item tidak punya data_mode: penyaring mode harus lewat pesanannya.
        DB::table('customer_orders')->where('id', $order->id)->update(['data_mode' => 'demo']);

        $resolved = app(StockMovementReferences::class)->resolve(StockMovement::whereKey($movement->id)->get());

        $this->assertNull($resolved[$movement->id]);
    }

    public function test_other_movement_types_with_the_customer_order_reference_are_ignored(): void
    {
        [, $item, $variant] = $this->orderWithStockedItem();
        $movement = app(StockService::class)->applyMovement($variant->fresh(), 'adjustment', 1, 'customer_order_item', $item->id, 'uji');

        $resolved = app(StockMovementReferences::class)->resolve(StockMovement::whereKey($movement->id)->get());

        $this->assertNull($resolved[$movement->id]);
    }

    public function test_the_stock_ledger_endpoint_shows_the_customer_order_number(): void
    {
        [$order, $item, $variant] = $this->orderWithStockedItem();
        $movement = app(StockService::class)->applyMovement($variant->fresh(), 'sale', -2, 'customer_order_item', $item->id);
        $this->actingAsOwner();

        $row = collect($this->getJson('/api/v1/stock/movements?per_page=50')->assertOk()->json('data'))
            ->firstWhere('id', $movement->id);

        $this->assertSame(['type' => 'customer_order', 'id' => $order->id, 'number' => $order->order_number], $row['reference']);
    }
}
