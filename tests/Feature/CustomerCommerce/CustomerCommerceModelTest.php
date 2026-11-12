<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\CustomerOrderShipment;
use App\Models\CustomerOrderStatusEvent;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerOrderNumber;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §4) — model, relasi, cast, cap mode data, dan
 * penjaga "hanya-tambah" pada riwayat status.
 */
class CustomerCommerceModelTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    private function statusEvent(CustomerOrder $order, array $attrs = []): CustomerOrderStatusEvent
    {
        return $order->statusEvents()->create($attrs + [
            'status' => CustomerOrder::STATUS_AWAITING_CONFIRMATION,
            'actor_type' => CustomerOrderStatusEvent::ACTOR_CUSTOMER,
            'occurred_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------
    // Konfigurasi
    // ------------------------------------------------------------------

    public function test_config_carries_every_key_the_contract_names(): void
    {
        $this->assertSame(20160, config('customer_commerce.token_ttl_minutes'));
        $this->assertSame(20, config('customer_commerce.max_qty_per_line'));
        $this->assertSame(50, config('customer_commerce.max_lines_per_order'));
        $this->assertSame(10, config('customer_commerce.max_addresses'));
        $this->assertSame([], config('customer_commerce.tracking_url_templates'));
        $this->assertSame(
            [
                'register' => ['per_ip' => 5, 'per_email' => 3],
                'login' => ['per_ip' => 10, 'per_email' => 5],
                'storefront' => ['per_ip' => 120],
                'customer_api' => ['per_account' => 120],
                'checkout' => ['per_account' => 10],
            ],
            config('customer_commerce.rate_limits'),
        );
    }

    // ------------------------------------------------------------------
    // CustomerAccount
    // ------------------------------------------------------------------

    public function test_account_email_is_stored_trimmed_and_lower_cased(): void
    {
        $account = CustomerAccount::factory()->create(['email' => "  Pembeli.SATU@Example.TEST \n"]);

        $this->assertSame('pembeli.satu@example.test', $account->email);
        $this->assertSame('pembeli.satu@example.test', DB::table('customer_accounts')->where('id', $account->id)->value('email'));
    }

    public function test_account_password_is_hashed_and_never_serialised(): void
    {
        $account = CustomerAccount::factory()->create(['password' => 'rahasia-uji-123']);
        $stored = DB::table('customer_accounts')->where('id', $account->id)->value('password');

        $this->assertNotSame('rahasia-uji-123', $stored);
        $this->assertTrue(Hash::check('rahasia-uji-123', $stored));
        $this->assertArrayNotHasKey('password', $account->fresh()->toArray());
    }

    public function test_account_factory_states(): void
    {
        $this->assertNull(CustomerAccount::factory()->create()->disabled_at);
        $this->assertNotNull(CustomerAccount::factory()->create()->email_verified_at);
        $this->assertInstanceOf(Carbon::class, CustomerAccount::factory()->disabled()->create()->disabled_at);
        $this->assertNull(CustomerAccount::factory()->unverified()->create()->email_verified_at);
    }

    public function test_account_relations(): void
    {
        $account = $this->makeCustomerAccount(['email' => 'relasi@example.test'], ['name' => 'Pembeli Relasi']);
        $address = CustomerAddress::factory()->create(['customer_account_id' => $account->id, 'is_default' => true]);
        $order = CustomerOrder::factory()->create(['customer_account_id' => $account->id]);

        $this->assertSame('Pembeli Relasi', $account->customer->name);
        $this->assertTrue($account->addresses->first()->is($address));
        $this->assertTrue($address->account->is($account));
        $this->assertTrue($address->is_default);
        $this->assertTrue($account->orders->first()->is($order));
        $this->assertSame($account->customer_id, $order->customer_id, 'Pesanan factory memakai kontak milik akunnya.');

        // Sisi Customer (kontak staf): akun dan pesanan pelanggan.
        $customer = Customer::findOrFail($account->customer_id);
        $this->assertTrue($customer->account->is($account));
        $this->assertTrue($customer->customerOrders->first()->is($order));
        $this->assertNull(Customer::factory()->create()->account);
    }

    public function test_customer_token_carries_the_customer_ability_and_an_expiry(): void
    {
        $this->travelTo(Carbon::parse('2026-11-11 08:00:00'));
        $account = $this->makeCustomerAccount();

        $plain = $this->issueCustomerToken($account);
        $token = PersonalAccessToken::findToken($plain);

        $this->assertTrue($token->tokenable->is($account));
        $this->assertSame(['customer'], $token->abilities);
        $this->assertTrue($token->can('customer'));
        $this->assertFalse($token->can('*'));
        $this->assertTrue(now()->addMinutes(20160)->equalTo($token->expires_at));
    }

    // ------------------------------------------------------------------
    // Mode data (DEMO/LIVE)
    // ------------------------------------------------------------------

    public function test_accounts_and_orders_are_stamped_with_the_active_mode(): void
    {
        $live = CustomerOrder::factory()->create();
        $demo = ModeGate::runAs('demo', fn () => CustomerOrder::factory()->create());

        $this->assertSame('live', $live->data_mode);
        $this->assertSame('live', $live->account->data_mode);
        $this->assertSame('demo', $demo->data_mode);
        $this->assertSame('demo', DB::table('customer_accounts')->where('id', $demo->customer_account_id)->value('data_mode'));
        $this->assertSame('demo', DB::table('customers')->where('id', $demo->customer_id)->value('data_mode'));
    }

    public function test_rows_of_the_other_mode_are_invisible(): void
    {
        $live = CustomerOrder::factory()->create();
        $demo = ModeGate::runAs('demo', fn () => CustomerOrder::factory()->create());

        $this->assertSame([$live->id], CustomerOrder::pluck('id')->all());
        $this->assertSame([$live->customer_account_id], CustomerAccount::pluck('id')->all());
        $this->assertNull(CustomerOrder::where('order_number', $demo->order_number)->first());

        ModeGate::runAs('demo', function () use ($demo) {
            $this->assertSame([$demo->id], CustomerOrder::pluck('id')->all());
            $this->assertSame([$demo->customer_account_id], CustomerAccount::pluck('id')->all());
        });
    }

    public function test_a_token_minted_in_one_mode_does_not_resolve_its_account_in_the_other(): void
    {
        $account = $this->makeCustomerAccount();
        $plain = $this->issueCustomerToken($account);

        $this->assertTrue(PersonalAccessToken::findToken($plain)->tokenable->is($account));

        // Toko dipindah ke DEMO: akun LIVE tidak boleh "masuk" ke data DEMO.
        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'demo', 'type' => 'string', 'group' => 'system']);

        $this->assertNull(PersonalAccessToken::findToken($plain)->tokenable);
    }

    // ------------------------------------------------------------------
    // CustomerOrder dan anak-anaknya
    // ------------------------------------------------------------------

    public function test_order_casts_and_relations(): void
    {
        $address = ['recipient_name' => 'Penerima Uji', 'phone' => '0800-0000-0001', 'address_line' => 'Jl. Contoh No. 1',
            'city' => 'Bandung', 'province' => 'Jawa Barat', 'postal_code' => '40111', 'notes' => null];
        $order = CustomerOrder::factory()->courier()->create([
            'shipping_address' => $address, 'subtotal' => 150000, 'shipping_cost' => 12000, 'total' => 162000,
        ]);
        $variant = ProductVariant::factory()->create();
        $item = CustomerOrderItem::factory()->create(['customer_order_id' => $order->id, 'variant_id' => $variant->id, 'unit_price' => 75000, 'qty' => 2, 'line_total' => 150000]);
        $shipment = CustomerOrderShipment::factory()->create(['customer_order_id' => $order->id, 'shipping_cost' => 12000]);

        $order = $order->fresh();

        $this->assertSame(CustomerOrder::FULFILMENT_COURIER, $order->fulfilment_method);
        $this->assertSame(CustomerOrder::STATUS_AWAITING_CONFIRMATION, $order->status);
        $this->assertEquals($address, $order->shipping_address); // JSON MySQL tidak menjaga urutan kunci
        $this->assertSame('150000.00', $order->subtotal);
        $this->assertSame('12000.00', $order->shipping_cost);
        $this->assertSame('162000.00', $order->total);
        $this->assertNull($order->stock_deducted_at);
        $this->assertNull($order->confirmed_at);

        $this->assertTrue($order->items->first()->is($item));
        $this->assertTrue($item->order->is($order));
        $this->assertTrue($item->variant->is($variant));
        $this->assertSame('75000.00', $item->fresh()->unit_price);
        $this->assertSame(2, $item->fresh()->qty);

        $this->assertTrue($order->shipment->is($shipment));
        $this->assertTrue($shipment->order->is($order));
        $this->assertSame('12000.00', $shipment->fresh()->shipping_cost);
        $this->assertInstanceOf(Carbon::class, $shipment->fresh()->dispatched_at);
        $this->assertNull($shipment->fresh()->delivered_at);
    }

    public function test_a_pickup_order_has_no_address_and_no_shipment(): void
    {
        $order = CustomerOrder::factory()->create();

        $this->assertSame(CustomerOrder::FULFILMENT_PICKUP, $order->fulfilment_method);
        $this->assertNull($order->shipping_address);
        $this->assertNull($order->shipment);
    }

    public function test_status_constants_match_the_database_enums(): void
    {
        $enum = function (string $table, string $column): array {
            $type = DB::selectOne(
                'SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [DB::getDatabaseName(), $table, $column],
            )->t;

            return str_getcsv(substr($type, 5, -1), ',', "'");
        };

        $this->assertSame(CustomerOrder::STATUSES, $enum('customer_orders', 'status'));
        $this->assertSame(CustomerOrder::STATUSES, $enum('customer_order_status_events', 'status'));
        $this->assertSame(CustomerOrder::FULFILMENT_METHODS, $enum('customer_orders', 'fulfilment_method'));
        $this->assertSame(CustomerOrderStatusEvent::EVENT_TYPES, $enum('customer_order_status_events', 'event_type'));
        $this->assertSame(CustomerOrderStatusEvent::ACTOR_TYPES, $enum('customer_order_status_events', 'actor_type'));
    }

    public function test_status_events_keep_their_audit_detail_out_of_serialisation(): void
    {
        $order = CustomerOrder::factory()->create();
        $staff = User::factory()->create();
        $event = $this->statusEvent($order, [
            'status' => CustomerOrder::STATUS_CONFIRMED, 'actor_type' => CustomerOrderStatusEvent::ACTOR_STAFF,
            'actor_user_id' => $staff->id, 'note' => 'Pesanan dikonfirmasi.', 'metadata' => ['old_status' => 'awaiting_confirmation'],
        ])->fresh();

        $this->assertSame(CustomerOrderStatusEvent::EVENT_STATUS_CHANGED, $event->event_type);
        $this->assertSame(['old_status' => 'awaiting_confirmation'], $event->metadata);
        $this->assertTrue($event->actor->is($staff));
        $this->assertTrue($event->order->is($order));
        $this->assertInstanceOf(Carbon::class, $event->occurred_at);
        $this->assertNotNull($event->created_at);

        // Detail audit staf tidak pernah ikut serialisasi bawaan (yang bisa sampai ke pelanggan).
        $this->assertArrayNotHasKey('metadata', $event->toArray());
        $this->assertArrayNotHasKey('actor_user_id', $event->toArray());
    }

    public function test_status_events_are_listed_newest_first(): void
    {
        $order = CustomerOrder::factory()->create();
        $old = $this->statusEvent($order, ['occurred_at' => now()->subHour()]);
        $new = $this->statusEvent($order, ['status' => CustomerOrder::STATUS_CONFIRMED, 'occurred_at' => now()]);

        $this->assertSame([$new->id, $old->id], $order->statusEvents()->pluck('id')->all());
    }

    public function test_status_events_are_append_only(): void
    {
        $order = CustomerOrder::factory()->create();
        $event = $this->statusEvent($order, ['note' => 'Asli']);

        foreach ([
            fn () => $event->update(['note' => 'Diubah']),
            fn () => $event->delete(),
            fn () => CustomerOrderStatusEvent::destroy($event->id),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Riwayat status tidak boleh diubah atau dihapus.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }

        $this->assertSame('Asli', DB::table('customer_order_status_events')->where('id', $event->id)->value('note'));
    }

    // ------------------------------------------------------------------
    // Nomor pesanan
    // ------------------------------------------------------------------

    public function test_order_numbers_are_random_crockford_codes(): void
    {
        $numbers = array_map(fn () => CustomerOrderNumber::generate(), range(1, 50));

        foreach ($numbers as $number) {
            $this->assertMatchesRegularExpression('/^CO-[0-9A-HJKMNP-TV-Z]{10}$/', $number);
            $this->assertMatchesRegularExpression('/^'.CustomerOrderNumber::ROUTE_PATTERN.'$/', $number);
        }
        $this->assertCount(50, array_unique($numbers), 'Nomor acak, bukan urut.');
    }

    public function test_order_number_generation_retries_when_the_number_is_taken_in_either_mode(): void
    {
        // Nomor pertama sudah dipakai pesanan DEMO (tak terlihat oleh scope mode aktif): harus tetap dilewati.
        ModeGate::runAs('demo', fn () => CustomerOrder::factory()->create(['order_number' => 'CO-AAAAAAAAAA']));
        $candidates = ['CO-AAAAAAAAAA', 'CO-AAAAAAAAAA', 'CO-BBBBBBBBBB'];

        $number = CustomerOrderNumber::generate(function () use (&$candidates) {
            return array_shift($candidates);
        });

        $this->assertSame('CO-BBBBBBBBBB', $number);
    }
}
