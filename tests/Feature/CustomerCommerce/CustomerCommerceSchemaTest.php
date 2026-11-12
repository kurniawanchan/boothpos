<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\CustomerOrderShipment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §4) — aturan yang dijaga BASIS DATA, bukan hanya
 * kode: keunikan, perilaku FK saat induk dihapus, dan nilai enum. Baris dibuat
 * lewat factory, pelanggaran dicoba lewat DB::table() supaya yang diuji adalah
 * constraint-nya sendiri (model/scope tidak ikut campur).
 */
class CustomerCommerceSchemaTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,mixed> salinan satu baris tanpa id, untuk dicoba disisipkan ulang */
    private function copyOf(string $table, int $id): array
    {
        $row = (array) DB::table($table)->where('id', $id)->first();
        unset($row['id']);

        return $row;
    }

    private function expectFailure(string $class, \Closure $write): void
    {
        try {
            $write();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e);

            return;
        }

        $this->fail("Seharusnya {$class}.");
    }

    public function test_only_the_two_root_tables_carry_a_data_mode_column(): void
    {
        foreach (['customer_accounts', 'customer_orders'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'data_mode'), $table);
        }

        // Anak hanya dijangkau lewat induknya (pola `via` milik order_reference_images dkk.).
        foreach (['customer_addresses', 'customer_order_items', 'customer_order_shipments', 'customer_order_status_events'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'data_mode'), $table);
        }

        $this->assertFalse(Schema::hasColumn('customer_order_status_events', 'updated_at'), 'Riwayat status hanya punya created_at.');
    }

    public function test_one_customer_has_at_most_one_account(): void
    {
        $account = CustomerAccount::factory()->create();

        $this->expectFailure(UniqueConstraintViolationException::class, fn () => DB::table('customer_accounts')->insert(
            ['email' => 'lain@example.test'] + $this->copyOf('customer_accounts', $account->id),
        ));
    }

    public function test_account_email_is_unique_per_data_mode(): void
    {
        $account = CustomerAccount::factory()->create(['email' => 'sama@example.test']);
        $other = CustomerAccount::factory()->create();
        $row = ['email' => 'sama@example.test'] + $this->copyOf('customer_accounts', $other->id);
        DB::table('customer_accounts')->where('id', $other->id)->delete();

        // E-mail yang sama boleh ada di mode lain (uji coba di DEMO tidak mengunci LIVE)…
        DB::table('customer_accounts')->insert(['data_mode' => 'demo'] + $row);
        $this->assertSame(2, DB::table('customer_accounts')->where('email', 'sama@example.test')->count());
        DB::table('customer_accounts')->where('data_mode', 'demo')->delete();

        // …tetapi tidak dua kali di mode yang sama.
        $this->assertSame('live', $account->data_mode);
        $this->expectFailure(UniqueConstraintViolationException::class, fn () => DB::table('customer_accounts')->insert($row));
    }

    public function test_order_number_is_unique_across_both_modes(): void
    {
        $order = CustomerOrder::factory()->create();
        $row = ['idempotency_key' => 'kunci-lain-0123456789', 'data_mode' => 'demo'] + $this->copyOf('customer_orders', $order->id);

        $this->expectFailure(UniqueConstraintViolationException::class, fn () => DB::table('customer_orders')->insert($row));
    }

    public function test_an_idempotency_key_is_unique_per_account_but_reusable_by_another_account(): void
    {
        $order = CustomerOrder::factory()->create(['idempotency_key' => 'kunci-sama-0123456789']);
        $other = CustomerOrder::factory()->create(['idempotency_key' => 'kunci-sama-0123456789']);

        $this->assertNotSame($order->customer_account_id, $other->customer_account_id);

        $this->expectFailure(UniqueConstraintViolationException::class, fn () => DB::table('customer_orders')->insert(
            ['order_number' => 'CO-0000000000'] + $this->copyOf('customer_orders', $order->id),
        ));
    }

    public function test_an_order_has_at_most_one_shipment(): void
    {
        $shipment = CustomerOrderShipment::factory()->create();

        $this->expectFailure(UniqueConstraintViolationException::class, fn () => DB::table('customer_order_shipments')->insert(
            $this->copyOf('customer_order_shipments', $shipment->id),
        ));
    }

    public function test_a_customer_with_an_account_or_orders_cannot_be_hard_deleted(): void
    {
        $order = CustomerOrder::factory()->create();

        // RESTRICT: wipe/hapus yang terlewat harus gagal keras, bukan diam-diam menghapus akun atau pesanan.
        $this->expectFailure(QueryException::class, fn () => DB::table('customers')->where('id', $order->customer_id)->delete());
        $this->expectFailure(QueryException::class, fn () => DB::table('customer_accounts')->where('id', $order->customer_account_id)->delete());

        $this->assertTrue(DB::table('customer_orders')->where('id', $order->id)->exists());
    }

    public function test_a_variant_referenced_by_a_customer_order_item_cannot_be_hard_deleted(): void
    {
        $item = CustomerOrderItem::factory()->create();

        $this->expectFailure(QueryException::class, fn () => DB::table('product_variants')->where('id', $item->variant_id)->delete());
    }

    public function test_deleting_an_account_cascades_to_its_addresses_only(): void
    {
        $address = CustomerAddress::factory()->create();
        $customerId = CustomerAccount::findOrFail($address->customer_account_id)->customer_id;

        DB::table('customer_accounts')->where('id', $address->customer_account_id)->delete();

        $this->assertFalse(DB::table('customer_addresses')->where('id', $address->id)->exists());
        $this->assertTrue(DB::table('customers')->where('id', $customerId)->exists(), 'Kontak staf tidak ikut terhapus.');
    }

    public function test_deleting_an_order_cascades_to_items_shipment_and_status_events(): void
    {
        $order = CustomerOrder::factory()->create();
        CustomerOrderItem::factory()->create(['customer_order_id' => $order->id]);
        CustomerOrderShipment::factory()->create(['customer_order_id' => $order->id]);
        DB::table('customer_order_status_events')->insert([
            'customer_order_id' => $order->id, 'status' => 'awaiting_confirmation', 'actor_type' => 'customer',
            'occurred_at' => now(), 'created_at' => now(),
        ]);

        DB::table('customer_orders')->where('id', $order->id)->delete();

        foreach (['customer_order_items', 'customer_order_shipments', 'customer_order_status_events'] as $table) {
            $this->assertSame(0, DB::table($table)->where('customer_order_id', $order->id)->count(), $table);
        }
    }

    public function test_removing_a_staff_user_keeps_the_status_event_and_clears_the_actor(): void
    {
        $order = CustomerOrder::factory()->create();
        $staff = User::factory()->create();
        $eventId = DB::table('customer_order_status_events')->insertGetId([
            'customer_order_id' => $order->id, 'status' => 'confirmed', 'actor_type' => 'staff', 'actor_user_id' => $staff->id,
            'occurred_at' => now(), 'created_at' => now(),
        ]);

        DB::table('users')->where('id', $staff->id)->delete();

        $event = DB::table('customer_order_status_events')->where('id', $eventId)->first();
        $this->assertNotNull($event, 'Riwayat status tidak boleh hilang bersama penggunanya.');
        $this->assertNull($event->actor_user_id);
        $this->assertSame('status_changed', $event->event_type, 'Jenis peristiwa bawaan.');
    }

    public function test_enum_columns_refuse_unknown_values(): void
    {
        $order = CustomerOrder::factory()->create();

        $this->assertSame('awaiting_confirmation', DB::table('customer_orders')->where('id', $order->id)->value('status'));

        $this->expectFailure(QueryException::class, fn () => DB::table('customer_orders')->where('id', $order->id)->update(['status' => 'paid']));
        $this->expectFailure(QueryException::class, fn () => DB::table('customer_orders')->where('id', $order->id)->update(['fulfilment_method' => 'drone']));
        $this->expectFailure(QueryException::class, fn () => DB::table('customer_order_status_events')->insert([
            'customer_order_id' => $order->id, 'status' => 'confirmed', 'actor_type' => 'robot', 'occurred_at' => now(), 'created_at' => now(),
        ]));
    }

    public function test_money_columns_default_to_zero_where_the_contract_says_so(): void
    {
        $order = CustomerOrder::factory()->create();
        $row = $this->copyOf('customer_orders', $order->id);
        unset($row['shipping_cost'], $row['status'], $row['data_mode']);

        $id = DB::table('customer_orders')->insertGetId(['order_number' => 'CO-1111111111', 'idempotency_key' => 'kunci-baru-0123456789'] + $row);
        $fresh = DB::table('customer_orders')->where('id', $id)->first();

        $this->assertSame('0.00', $fresh->shipping_cost);
        $this->assertSame('awaiting_confirmation', $fresh->status);
        $this->assertSame('live', $fresh->data_mode);
    }
}
