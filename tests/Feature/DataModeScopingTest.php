<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\CashierSession;
use App\Models\Concerns\DataModeScope;
use App\Models\Order;
use App\Models\Setting;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 003-seed-demo-live T009 — memverifikasi mekanisme HasDataMode/ModeGate/
 * DataModeScope itu sendiri (bukan fitur seed data atau toggle UI), lewat
 * dua model representatif: Outlet (master data, punya factory) dan Order
 * (transaksional, tanpa factory — dibuat langsung lewat create()).
 */
class DataModeScopingTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(string $orderNumber): Order
    {
        $session = CashierSession::factory()->create();

        return Order::create([
            'order_number' => $orderNumber,
            'event_id' => $session->event_id,
            'session_id' => $session->id,
            'user_id' => $session->user_id,
        ]);
    }

    public function test_creating_inside_run_as_demo_stamps_demo(): void
    {
        $artist = ModeGate::runAs('demo', fn () => Outlet::factory()->create());

        $this->assertSame('demo', $artist->data_mode);
    }

    public function test_creating_with_no_override_and_no_setting_stamps_live(): void
    {
        // Tidak ada baris 'system_mode' di settings sama sekali.
        $artist = Outlet::factory()->create();

        $this->assertSame('live', $artist->data_mode);
    }

    public function test_creating_with_system_mode_set_to_demo_stamps_demo(): void
    {
        Setting::updateOrCreate(
            ['key' => 'system_mode'],
            ['value' => 'demo', 'type' => 'string', 'group' => 'system']
        );

        $artist = Outlet::factory()->create();

        $this->assertSame('demo', $artist->data_mode);
    }

    public function test_default_query_never_returns_the_other_modes_rows(): void
    {
        $demoOutlet = ModeGate::runAs('demo', fn () => Outlet::factory()->create(['name' => 'Outlet Demo']));
        $liveOutlet = ModeGate::runAs('live', fn () => Outlet::factory()->create(['name' => 'Outlet Live']));

        $seenWhileLive = ModeGate::runAs('live', fn () => Outlet::pluck('id')->all());
        $seenWhileDemo = ModeGate::runAs('demo', fn () => Outlet::pluck('id')->all());

        $this->assertContains($liveOutlet->id, $seenWhileLive);
        $this->assertNotContains($demoOutlet->id, $seenWhileLive);

        $this->assertContains($demoOutlet->id, $seenWhileDemo);
        $this->assertNotContains($liveOutlet->id, $seenWhileDemo);
    }

    public function test_scoping_also_applies_to_transactional_models_without_factories(): void
    {
        $demoOrder = ModeGate::runAs('demo', fn () => $this->makeOrder('TRX-DEMO-0001'));
        $liveOrder = ModeGate::runAs('live', fn () => $this->makeOrder('TRX-LIVE-0001'));

        $this->assertSame('demo', $demoOrder->data_mode);
        $this->assertSame('live', $liveOrder->data_mode);

        $seenWhileLive = ModeGate::runAs('live', fn () => Order::pluck('id')->all());
        $this->assertContains($liveOrder->id, $seenWhileLive);
        $this->assertNotContains($demoOrder->id, $seenWhileLive);
    }

    public function test_without_global_scope_bypasses_the_filter(): void
    {
        ModeGate::runAs('demo', fn () => Outlet::factory()->create());
        ModeGate::runAs('live', fn () => Outlet::factory()->create());

        $all = Outlet::withoutGlobalScope(DataModeScope::class)->get();

        $this->assertCount(2, $all);
        $this->assertEqualsCanonicalizing(['demo', 'live'], $all->pluck('data_mode')->unique()->values()->all());
    }
}
