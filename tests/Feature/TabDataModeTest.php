<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 028 — tab, meja, shift dan item milik mode DEMO tidak pernah terlihat atau
 * tersentuh dari mode LIVE (dan sebaliknya) — FR-040.
 */
class TabDataModeTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
    }

    public function test_a_demo_tab_is_invisible_and_untouchable_from_live(): void
    {
        [$tab, $variant] = ModeGate::runAs('demo', function () {
            $session = $this->makeShift($this->cashier);

            return [$this->makeOpenOrder($session, $this->cashier, [
                'table_id' => DiningTable::factory()->create()->id, 'service_type' => 'dine_in',
            ]), $this->makeVariant()];
        });

        $this->getJson("/api/v1/tabs/{$tab->id}")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/tabs')->json('data'));
        $this->apiAddLine($tab->id, $variant->id)->assertNotFound();
        $this->apiPay($tab->id, [['method' => 'cash', 'amount' => 1000]])->assertNotFound();
        $this->deleteJson("/api/v1/tabs/{$tab->id}")->assertNotFound();
    }

    public function test_a_demo_shift_cannot_open_a_live_tab(): void
    {
        $demoSession = ModeGate::runAs('demo', fn () => $this->makeShift($this->cashier));

        $this->apiOpenTab($demoSession->id)->assertNotFound();
    }

    public function test_a_demo_variant_cannot_be_added_to_a_live_tab(): void
    {
        $tab = $this->apiOpenTab($this->makeShift($this->cashier)->id)->json('id');
        $demoVariant = ModeGate::runAs('demo', fn () => $this->makeVariant());

        $this->apiAddLine($tab, $demoVariant->id)->assertNotFound();
    }

    public function test_a_tab_opened_in_live_is_stamped_live(): void
    {
        $tab = $this->apiOpenTab($this->makeShift($this->cashier)->id)->json('id');

        $this->assertDatabaseHas('orders', ['id' => $tab, 'data_mode' => 'live']);
    }
}
