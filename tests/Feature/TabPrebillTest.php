<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** 048 / T029 (B13) — pra-bill tercetak dan field baru pada denah. */
class TabPrebillTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $waiter;

    private int $tab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->waiter = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->waiter, 'sanctum');
        $session = $this->makeShift($this->waiter)->id;
        $table = DiningTable::factory()->create(['name' => '3', 'area' => 'Indoor']);
        $outlet = Outlet::first();
        $device = Device::forceCreate(['outlet_id' => $outlet->id, 'code' => 'T01', 'name' => 'x']);
        $this->tab = $this->postJson('/api/v1/tabs', ['session_id' => $session, 'table_id' => $table->id, 'guest_count' => 2], ['X-Device-Id' => $device->id])->json('id');
        $this->apiAddLine($this->tab, $this->makeVariant(stock: 5, price: 10_000)->id, 1);
    }

    public function test_prebill_marks_the_tab_without_changing_status_or_total(): void
    {
        $this->postJson("/api/v1/tabs/{$this->tab}/prebill")->assertOk()
            ->assertJsonPath('status', 'open')->assertJsonPath('total_amount', '10000.00')->assertJson(fn ($j) => $j->whereNot('prebill_at', null)->etc());

        $this->assertSame(1, ActivityLog::where('action', 'tab_prebill')->count());
    }

    public function test_floor_shows_the_bill_printed_flag_pax_and_terminal(): void
    {
        $before = $this->getJson('/api/v1/floor')->json();
        $tabBefore = $before['areas'][0]['tables'][0]['tab'];
        $this->assertFalse($tabBefore['bill_printed']);
        $this->assertSame(2, $tabBefore['guest_count']);
        $this->assertSame('T01', $tabBefore['device_code']);

        $this->postJson("/api/v1/tabs/{$this->tab}/prebill")->assertOk();

        $after = $this->getJson('/api/v1/floor')->json();
        $this->assertTrue($after['areas'][0]['tables'][0]['tab']['bill_printed']);
        $this->assertNotSame($before['version'], $after['version'], 'versi harus berubah agar terminal lain menarik denah baru');
    }

    public function test_floor_version_is_stable_when_nothing_changes(): void
    {
        $a = $this->getJson('/api/v1/floor')->json('version');
        $this->travel(2)->minutes();
        $this->assertSame($a, $this->getJson('/api/v1/floor')->json('version'));
    }

    public function test_prebill_on_a_paid_tab_is_refused(): void
    {
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 10_000]])->assertOk();

        $this->postJson("/api/v1/tabs/{$this->tab}/prebill")->assertStatus(409);
    }

    public function test_a_user_without_the_floor_menu_cannot_prebill(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'inventory']), 'sanctum');

        $this->postJson("/api/v1/tabs/{$this->tab}/prebill")->assertForbidden();
    }
}
