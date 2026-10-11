<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 044-sales-table-view (T003) — GET /tabs/{id} dipakai papan Meja di halaman Sales sebagai detail BACA-SAJA:
 * `opened_by` ditambahkan, tidak ada biaya, dan status akhir (selesai/batal) terbaca jelas.
 */
class TabDetailReadTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier', 'name' => 'Kasir Satu']);
        $this->actingAs($this->cashier, 'sanctum');
    }

    private function openTab(array $over = [])
    {
        $session = $this->makeShift($this->cashier);
        $table = DiningTable::factory()->create(['name' => 'M4', 'area' => 'Indoor']);
        $order = $this->makeOpenOrder($session, $this->cashier, array_merge(['table_id' => $table->id, 'service_type' => 'dine_in', 'total_amount' => 25000], $over));
        $this->addLineTo($order, $this->makeVariant(price: 25000), 1);

        return $order;
    }

    public function test_the_detail_names_the_cashier_who_opened_the_tab(): void
    {
        $order = $this->openTab();

        $this->getJson("/api/v1/tabs/{$order->id}")->assertOk()
            ->assertJsonPath('opened_by', 'Kasir Satu')
            ->assertJsonPath('table.name', 'M4')
            ->assertJsonPath('table.area', 'Indoor')
            ->assertJsonPath('status', 'open');
    }

    public function test_the_detail_never_carries_cost_or_margin_fields(): void
    {
        $order = $this->openTab();

        $json = json_encode($this->getJson("/api/v1/tabs/{$order->id}")->assertOk()->json());

        foreach (['cost_price', 'total_cost', 'margin', 'cost'] as $forbidden) {
            $this->assertStringNotContainsString('"'.$forbidden.'"', $json, "Detail tab tidak boleh memuat '{$forbidden}'.");
        }
    }

    public function test_a_completed_tab_is_returned_with_its_final_status_so_the_ui_can_say_it_was_paid(): void
    {
        $order = $this->openTab(['status' => 'completed']);

        $this->getJson("/api/v1/tabs/{$order->id}")->assertOk()->assertJsonPath('status', 'completed');
    }

    public function test_a_voided_tab_is_a_404(): void
    {
        $order = $this->openTab(['status' => 'voided']);

        $this->getJson("/api/v1/tabs/{$order->id}")->assertNotFound();
    }

    public function test_a_role_without_the_floor_menu_is_refused(): void
    {
        $order = $this->openTab();
        $this->actingAs(User::factory()->create(['role' => 'inventory']), 'sanctum');

        $this->getJson("/api/v1/tabs/{$order->id}")->assertForbidden();
    }

    public function test_an_order_of_the_other_data_mode_is_neither_on_the_floor_nor_readable(): void
    {
        $session = $this->makeShift($this->cashier);
        $demo = ModeGate::runAs('demo', fn () => $this->makeOpenOrder($session, $this->cashier, ['service_type' => 'takeaway', 'tab_label' => 'Demo', 'total_amount' => 9000]));

        $this->getJson("/api/v1/tabs/{$demo->id}")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/floor')->assertOk()->json('takeaway_tabs'));
    }

    public function test_a_floor_role_without_the_products_or_reports_menu_still_sees_no_cost_on_the_floor_payload(): void
    {
        $this->openTab();

        $json = json_encode($this->getJson('/api/v1/floor')->assertOk()->json());

        foreach (['cost_price', 'total_cost', 'margin'] as $forbidden) {
            $this->assertStringNotContainsString('"'.$forbidden.'"', $json);
        }
    }
}
