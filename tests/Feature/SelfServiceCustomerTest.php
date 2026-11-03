<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\SelfServiceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SelfServiceFixtures;
use Tests\TestCase;

/** 090 / US4 — tamu atau data diri: validasi, tersimpan di entitas, tertaut tanpa duplikat saat dispatch, tanpa bocor. */
class SelfServiceCustomerTest extends TestCase
{
    use RefreshDatabase;
    use SelfServiceFixtures;

    private $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ssSetUp();
        $this->item = $this->makeMenuItem(stock: 20, price: 10000);
    }

    private function place(?array $customer = null, array $extra = [])
    {
        \Illuminate\Support\Facades\Cache::flush();
        $payload = $this->ssPayload([['variant_id' => $this->item->id, 'qty' => 1]], $extra + ($customer === null ? [] : ['customer' => $customer]));

        return $this->postJson($this->ssUrl('/orders'), $payload);
    }

    private function dispatchFor(SelfServiceOrder $row): void
    {
        $owner = $this->ssOwner();
        $this->makeShift($owner);
        $this->actingAs($owner, 'sanctum');
        $this->postJson("/api/v1/self-service/orders/{$row->id}/verify", ['reference' => 'BCA-1234'])->assertOk();
        $this->postJson("/api/v1/self-service/orders/{$row->id}/dispatch")->assertOk();
    }

    public function test_a_guest_order_has_no_personal_data_and_creates_no_customer(): void
    {
        $this->place()->assertCreated();

        $row = SelfServiceOrder::firstOrFail();
        $this->assertNull($row->customer_name);
        $this->assertNull($row->customer_phone);
        $this->assertSame(0, Customer::count());
    }

    public function test_an_empty_customer_block_counts_as_a_guest(): void
    {
        $this->place(['name' => '', 'phone' => ' ', 'email' => null])->assertCreated();

        $this->assertNull(SelfServiceOrder::firstOrFail()->customer_name);
    }

    public function test_details_are_kept_on_the_order_in_canonical_form_but_not_in_master_data_yet(): void
    {
        $this->place(['name' => ' Budi ', 'phone' => '+62 812-3456-7890', 'email' => 'Budi@Example.TEST'])->assertCreated();

        $row = SelfServiceOrder::firstOrFail();
        $this->assertSame('Budi', $row->customer_name);
        $this->assertSame('081234567890', $row->customer_phone);
        $this->assertSame('budi@example.test', $row->customer_email);
        $this->assertSame(0, Customer::count(), 'rute publik tidak menulis data master');
    }

    public function test_name_and_phone_are_required_once_any_detail_is_given(): void
    {
        $this->place(['name' => 'Budi'])->assertStatus(422)->assertJsonValidationErrors('customer.phone');
        $this->place(['phone' => '081234567890'])->assertStatus(422)->assertJsonValidationErrors('customer.name');
        $this->place(['email' => 'a@b.test'])->assertStatus(422)->assertJsonValidationErrors(['customer.name', 'customer.phone']);
        $this->place(['name' => 'Budi', 'phone' => '12'])->assertStatus(422)->assertJsonValidationErrors('customer.phone');
        $this->place(['name' => 'Budi', 'phone' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('customer.phone');
        $this->place(['name' => 'Budi', 'phone' => '081234567890', 'email' => 'bukan-surel'])->assertStatus(422)->assertJsonValidationErrors('customer.email');
        $this->place(['name' => str_repeat('x', 101), 'phone' => '081234567890'])->assertStatus(422)->assertJsonValidationErrors('customer.name');
        $this->assertSame(0, SelfServiceOrder::count());
    }

    public function test_email_is_optional(): void
    {
        $this->place(['name' => 'Budi', 'phone' => '081234567890'])->assertCreated();

        $this->assertNull(SelfServiceOrder::firstOrFail()->customer_email);
    }

    public function test_dispatch_creates_the_customer_and_links_the_order(): void
    {
        $this->place(['name' => 'Budi', 'phone' => '081234567890', 'email' => 'budi@example.test'])->assertCreated();
        $row = SelfServiceOrder::firstOrFail();

        $this->dispatchFor($row);

        $customer = Customer::sole();
        $this->assertSame('Budi', $customer->name);
        $this->assertSame('081234567890', $customer->phone);
        $this->assertSame($customer->id, (int) Order::sole()->customer_id);
    }

    public function test_the_same_number_written_differently_never_makes_a_duplicate_or_overwrites_the_old_record(): void
    {
        $old = Customer::create(['name' => 'Budi Lama', 'phone' => '0812 3456 7890', 'email' => 'lama@example.test']);

        foreach (['+6281234567890', '6281234567890', '081234567890'] as $phone) {
            $this->place(['name' => 'Nama Baru', 'phone' => $phone])->assertCreated();
        }
        foreach (SelfServiceOrder::orderBy('id')->get() as $row) {
            $this->dispatchFor($row);
        }

        $this->assertSame(1, Customer::count());
        $this->assertSame('Budi Lama', $old->fresh()->name);
        $this->assertSame('lama@example.test', $old->fresh()->email);
        $this->assertSame(3, Order::where('customer_id', $old->id)->count());
    }

    public function test_the_public_side_never_returns_stored_customer_data_to_anyone(): void
    {
        Customer::create(['name' => 'Pelanggan Lama', 'phone' => '081234567890', 'email' => 'lama@example.test']);

        $res = $this->place(['name' => 'Orang Lain', 'phone' => '081234567890'])->assertCreated();

        $raw = $res->getContent();
        foreach (['Pelanggan Lama', 'lama@example.test', '081234567890', 'customer'] as $leak) {
            $this->assertStringNotContainsString($leak, $raw);
        }
    }

    public function test_the_staff_list_masks_the_phone_and_the_detail_shows_everything(): void
    {
        $this->place(['name' => 'Budi', 'phone' => '081234567890', 'email' => 'budi@example.test'])->assertCreated();
        $row = SelfServiceOrder::firstOrFail();
        $this->actingAs($this->ssCashier(), 'sanctum');

        $list = $this->getJson('/api/v1/self-service/orders')->assertOk()->json('data.0.customer');
        $this->assertSame('Budi', $list['name']);
        $this->assertSame('0812****7890', $list['phone']);
        $this->assertArrayNotHasKey('email', $list);

        $detail = $this->getJson("/api/v1/self-service/orders/{$row->id}")->assertOk()->json('data.customer');
        $this->assertSame('081234567890', $detail['phone']);
        $this->assertSame('budi@example.test', $detail['email']);
    }

    public function test_a_guest_shows_no_customer_to_staff(): void
    {
        $this->place()->assertCreated();
        $this->actingAs($this->ssCashier(), 'sanctum');

        $this->getJson('/api/v1/self-service/orders')->assertJsonPath('data.0.customer', null);
    }

    public function test_no_personal_data_reaches_the_activity_log(): void
    {
        $this->place(['name' => 'Budi Rahasia', 'phone' => '081234567890', 'email' => 'budi@example.test'])->assertCreated();
        $this->dispatchFor(SelfServiceOrder::firstOrFail());

        $dump = json_encode(ActivityLog::all()->toArray());
        foreach (['Budi Rahasia', '081234567890', 'budi@example.test'] as $pii) {
            $this->assertStringNotContainsString($pii, $dump);
        }
    }
}
