<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 090 / US5 — Dine-In / Take Away di POS web (POST /orders) dan tab (POST /tabs, PUT /tabs/{id}/service-type).
 * Order lama tetap `retail`; laporan yang mengenal jenis layanan tidak berubah.
 */
class ServiceTypeTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;

    private int $shiftId;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->shiftId = $this->makeShift($this->cashier)->id;
        $this->variantId = $this->makeVariant(stock: 20, price: 8000)->id;
    }

    private function sale(array $extra = [])
    {
        return $this->postJson('/api/v1/orders', array_merge([
            'session_id' => $this->shiftId, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $this->variantId, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 8000]],
        ], $extra));
    }

    public function test_a_pos_sale_stores_dine_in_or_take_away(): void
    {
        $dineIn = $this->sale(['service_type' => 'dine_in'])->assertCreated()->assertJsonPath('service_type', 'dine_in');
        $takeaway = $this->sale(['service_type' => 'takeaway'])->assertCreated()->assertJsonPath('service_type', 'takeaway');

        $this->assertSame('dine_in', Order::findOrFail($dineIn->json('id'))->service_type);
        $this->assertSame('takeaway', Order::findOrFail($takeaway->json('id'))->service_type);
        $this->assertSame(Order::SOURCE_POS, Order::findOrFail($takeaway->json('id'))->source);
    }

    public function test_without_a_choice_an_older_client_still_gets_the_legacy_retail_type(): void
    {
        $res = $this->sale()->assertCreated()->assertJsonPath('service_type', 'retail');

        $this->assertSame('retail', Order::findOrFail($res->json('id'))->service_type);
    }

    public function test_only_dine_in_or_take_away_is_accepted_from_a_client(): void
    {
        foreach (['retail', 'delivery', '', 5] as $bad) {
            $this->sale(['service_type' => $bad])->assertStatus(422)->assertJsonValidationErrors('service_type');
        }
        $this->assertSame(0, Order::count());
    }

    public function test_an_order_cannot_be_flagged_self_service_by_a_pos_client(): void
    {
        $res = $this->sale(['service_type' => 'dine_in', 'source' => 'self_service', 'self_service_order_id' => 1])->assertCreated();

        $order = Order::findOrFail($res->json('id'));
        $this->assertSame(Order::SOURCE_POS, $order->source);
        $this->assertNull($order->self_service_order_id);
    }

    public function test_a_tab_keeps_its_derived_type_when_none_is_chosen(): void
    {
        $table = DiningTable::factory()->create();

        $this->apiOpenTab($this->shiftId, $table->id)->assertCreated()->assertJsonPath('service_type', 'dine_in');
        $this->apiOpenTab($this->shiftId)->assertCreated()->assertJsonPath('service_type', 'takeaway');
    }

    public function test_a_tab_can_be_opened_with_an_explicit_type(): void
    {
        $table = DiningTable::factory()->create();

        // Makan di meja tetapi dibungkus; dan duduk di konter tanpa meja.
        $this->apiOpenTab($this->shiftId, $table->id, ['service_type' => 'takeaway'])->assertCreated()->assertJsonPath('service_type', 'takeaway');
        $this->apiOpenTab($this->shiftId, null, ['service_type' => 'dine_in'])->assertCreated()->assertJsonPath('service_type', 'dine_in');
        $this->apiOpenTab($this->shiftId, null, ['service_type' => 'retail'])->assertStatus(422)->assertJsonValidationErrors('service_type');
    }

    public function test_an_open_tab_can_switch_between_dine_in_and_take_away_and_it_is_logged(): void
    {
        $tab = $this->apiOpenTab($this->shiftId)->assertCreated()->json('id');
        $this->assertSame('takeaway', Order::findOrFail($tab)->service_type);

        $this->putJson("/api/v1/tabs/{$tab}/service-type", ['service_type' => 'dine_in'])->assertOk()->assertJsonPath('service_type', 'dine_in');
        $this->assertSame('dine_in', Order::findOrFail($tab)->service_type);
        $this->assertNotNull(ActivityLog::where('action', 'service_type_changed')->where('entity_id', $tab)->first());

        $this->putJson("/api/v1/tabs/{$tab}/service-type", ['service_type' => 'takeaway'])->assertOk()->assertJsonPath('service_type', 'takeaway');
        $this->putJson("/api/v1/tabs/{$tab}/service-type", ['service_type' => 'retail'])->assertStatus(422);
        $this->putJson("/api/v1/tabs/{$tab}/service-type", [])->assertStatus(422);
    }

    public function test_switching_to_the_same_type_is_a_quiet_no_op(): void
    {
        $tab = $this->apiOpenTab($this->shiftId)->assertCreated()->json('id');

        $this->putJson("/api/v1/tabs/{$tab}/service-type", ['service_type' => 'takeaway'])->assertOk();

        $this->assertSame(0, ActivityLog::where('action', 'service_type_changed')->count());
    }

    public function test_only_an_open_tab_can_change_its_type(): void
    {
        $tab = $this->apiOpenTab($this->shiftId)->assertCreated()->json('id');
        $this->apiAddLine($tab, $this->variantId);
        $this->apiPay($tab, [['method' => 'cash', 'amount' => 8000]])->assertOk();

        $this->putJson("/api/v1/tabs/{$tab}/service-type", ['service_type' => 'dine_in'])->assertStatus(409);
        $this->assertSame('takeaway', Order::findOrFail($tab)->service_type);
    }

    public function test_a_role_without_the_floor_menu_cannot_change_it(): void
    {
        $tab = $this->apiOpenTab($this->shiftId)->assertCreated()->json('id');
        $this->actingAs(User::factory()->create(['role' => 'inventory']), 'sanctum');

        $this->putJson("/api/v1/tabs/{$tab}/service-type", ['service_type' => 'dine_in'])->assertForbidden();
    }

    public function test_the_type_is_visible_in_the_order_detail_and_the_sales_rows(): void
    {
        $id = $this->sale(['service_type' => 'takeaway'])->assertCreated()->json('id');

        $this->getJson("/api/v1/orders/{$id}")->assertOk()->assertJsonPath('service_type', 'takeaway')->assertJsonPath('source', 'pos');

        $today = \App\Support\BusinessDay::today();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $row = collect($this->getJson("/api/v1/reports/sales?date_from={$today}&date_to={$today}")->assertOk()->json('transactions'))->firstWhere('id', $id);
        $this->assertSame('takeaway', $row['service_type']);
        $this->assertSame('pos', $row['source']);
    }

    public function test_the_receipt_shows_the_service_type(): void
    {
        $id = $this->sale(['service_type' => 'dine_in'])->assertCreated()->json('id');

        $this->getJson("/api/v1/orders/{$id}/receipt")->assertOk()->assertJsonPath('service_type', 'dine_in');
    }

    /** Audit T073: order POS kini bukan `retail`, tetapi sudah `completed` — tidak boleh muncul sebagai tab terbuka atau di lantai. */
    public function test_a_finished_pos_sale_with_a_service_type_never_shows_up_as_an_open_tab_or_on_the_floor(): void
    {
        $this->sale(['service_type' => 'dine_in'])->assertCreated();
        $this->sale(['service_type' => 'takeaway'])->assertCreated();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $this->getJson('/api/v1/tabs')->assertOk()->assertJsonCount(0, 'data');
        $floor = $this->getJson('/api/v1/floor')->assertOk()->json();
        $this->assertSame([], $floor['takeaway_tabs']);
    }
}
