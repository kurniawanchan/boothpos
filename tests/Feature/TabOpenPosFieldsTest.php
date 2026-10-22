<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** 048 / T027 (B12/B13/B14) — jumlah tamu, perangkat, nomor antrean, dan nomor struk saat lunas. */
class TabOpenPosFieldsTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;

    private Device $device;

    private int $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->sessionId = $this->makeShift($this->cashier)->id;
        $outlet = Outlet::first() ?? Outlet::factory()->create();
        $this->device = Device::forceCreate(['outlet_id' => $outlet->id, 'name' => 'Tablet']);
    }

    private function open(array $body = [], ?int $device = null)
    {
        $headers = $device ? ['X-Device-Id' => $device] : [];

        return $this->postJson('/api/v1/tabs', array_merge(['session_id' => $this->sessionId], $body), $headers);
    }

    public function test_guest_count_device_and_nothing_else_are_recorded_for_a_table_tab(): void
    {
        $table = \App\Models\DiningTable::factory()->create();

        $this->open(['table_id' => $table->id, 'guest_count' => 3], $this->device->id)->assertCreated()
            ->assertJsonPath('guest_count', 3)->assertJsonPath('device.name', 'Tablet')->assertJsonMissingPath('device.code')->assertJsonPath('queue_no', null);
    }

    public function test_a_counter_tab_gets_a_queue_number_only_when_opened_from_a_terminal(): void
    {
        $this->open([], $this->device->id)->assertCreated()->assertJsonPath('queue_no', 'A-001');
        $this->apiOpenTab($this->sessionId)->assertJsonPath('queue_no', null);
    }

    public function test_guest_count_is_bounded(): void
    {
        $this->open(['guest_count' => 0])->assertStatus(422)->assertJsonValidationErrors('guest_count');
        $this->open(['guest_count' => 100])->assertStatus(422)->assertJsonValidationErrors('guest_count');
    }

    public function test_an_unknown_or_revoked_device_is_rejected(): void
    {
        $this->open([], 99999)->assertStatus(422)->assertJsonValidationErrors('device_id');

        $this->device->forceFill(['revoked_at' => now()])->save();
        $this->open([], $this->device->id)->assertStatus(422)->assertJsonValidationErrors('device_id');
    }

    public function test_a_device_of_another_outlet_is_not_accepted(): void
    {
        $other = Device::forceCreate(['outlet_id' => Outlet::factory()->create()->id, 'name' => 'Lain']);
        // pengguna kasir hanya ditugaskan ke outlet-nya → perangkat outlet lain tak terlihat
        $this->cashier->outlets()->sync([$this->device->outlet_id]);

        $this->open([], $other->id)->assertStatus(422)->assertJsonValidationErrors('device_id');
    }

    public function test_paying_in_full_allocates_the_outlet_receipt_number(): void
    {
        $table = \App\Models\DiningTable::factory()->create();
        $v = $this->makeVariant(stock: 10, price: 20_000);
        $id = $this->open(['table_id' => $table->id], $this->device->id)->json('id');
        $this->apiAddLine($id, $v->id, 1);

        $this->apiPay($id, [['method' => 'cash', 'amount' => 20_000]])->assertOk()
            ->assertJsonPath('status', 'completed')->assertJsonPath('receipt_no', '000001');
    }

    /** 064 — struk dan order membawa NAMA terminal; nomor struk berurutan per outlet lintas terminal. */
    public function test_the_receipt_and_the_order_carry_the_terminal_name_and_numbers_run_per_outlet(): void
    {
        $second = Device::forceCreate(['outlet_id' => $this->device->outlet_id, 'name' => 'Kasir Belakang']);
        $v = $this->makeVariant(stock: 10, price: 20_000);

        $ids = [];
        foreach ([$this->device, $second] as $device) {
            $id = $this->open([], $device->id)->json('id');
            $this->apiAddLine($id, $v->id, 1);
            $this->apiPay($id, [['method' => 'cash', 'amount' => 20_000]])->assertOk();
            $ids[$device->name] = $id;
        }

        $this->getJson("/api/v1/orders/{$ids['Tablet']}/receipt")->assertOk()
            ->assertJsonPath('terminal_name', 'Tablet')->assertJsonPath('receipt_no', '000001');
        $this->getJson("/api/v1/orders/{$ids['Kasir Belakang']}/receipt")->assertOk()
            ->assertJsonPath('terminal_name', 'Kasir Belakang')->assertJsonPath('receipt_no', '000002');
        $this->getJson("/api/v1/orders/{$ids['Tablet']}")->assertOk()
            ->assertJsonPath('terminal_name', 'Tablet')->assertJsonPath('terminal_id', $this->device->id);
    }

    public function test_an_order_without_a_terminal_has_a_null_terminal_name(): void
    {
        $v = $this->makeVariant(stock: 10, price: 20_000);
        $id = $this->apiOpenTab($this->sessionId)->json('id');
        $this->apiAddLine($id, $v->id, 1);
        $this->apiPay($id, [['method' => 'cash', 'amount' => 20_000]])->assertOk();

        $this->getJson("/api/v1/orders/{$id}/receipt")->assertOk()->assertJsonPath('terminal_name', null)->assertJsonPath('receipt_no', null);
    }

    public function test_a_partial_payment_does_not_consume_a_receipt_number(): void
    {
        $v = $this->makeVariant(stock: 10, price: 20_000);
        $id = $this->open([], $this->device->id)->json('id');
        $this->apiAddLine($id, $v->id, 1);

        $this->apiPay($id, [['method' => 'cash', 'amount' => 5_000]])->assertOk()->assertJsonPath('receipt_no', null);
        $this->assertSame(0, (int) DB::table('outlet_receipt_counters')->where('outlet_id', $this->device->outlet_id)->value('receipt_seq'));
    }

    public function test_a_tab_without_a_terminal_keeps_working_with_no_terminal_receipt(): void
    {
        $v = $this->makeVariant(stock: 10, price: 20_000);
        $id = $this->apiOpenTab($this->sessionId)->json('id');
        $this->apiAddLine($id, $v->id, 1);

        $this->apiPay($id, [['method' => 'cash', 'amount' => 20_000]])->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('receipt_no', null);
    }
}
