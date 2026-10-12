<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Customer;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 046-hide-events-preorders (US5) — instalasi yang punya event dan pre-order (dengan pembayaran dan stok yang sudah
 * bergerak) tetap berfungsi: baris lama tinggal sebagai riwayat diam, tidak menggeser satu angka pun yang terlihat.
 */
class UpgradeKeepsLegacyDataTest extends TestCase
{
    use RefreshDatabase;

    private function seedLegacyRows(User $user): void
    {
        $outletId = Outlet::query()->value('id') ?? Outlet::factory()->create()->id;
        $customerId = Customer::factory()->create()->id;

        $eventId = DB::table('events')->insertGetId([
            'name' => 'Bazaar Lama', 'location' => 'JCC', 'start_date' => '2026-09-01', 'end_date' => '2026-09-03',
            'status' => 'closed', 'event_cost' => 500000, 'data_mode' => 'demo', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $preorderId = DB::table('preorders')->insertGetId([
            'preorder_number' => 'PRE-LAMA-9', 'customer_id' => $customerId, 'user_id' => $user->id, 'event_id' => $eventId,
            'status' => 'handed_over', 'fulfillment' => 'pickup', 'subtotal' => 900000, 'total_amount' => 900000, 'paid_amount' => 900000,
            'outlet_id' => $outletId, 'data_mode' => 'demo', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('payments')->insert([
            'preorder_id' => $preorderId, 'outlet_id' => $outletId, 'method' => 'cash', 'purpose' => 'full', 'amount' => 900000,
            'verification' => 'verified', 'paid_at' => now(), 'business_date' => \App\Support\BusinessDay::today(),
            'data_mode' => 'demo', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_reports_dashboard_and_shift_ignore_legacy_events_and_preorders(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');
        $session = CashierSession::factory()->create(['user_id' => $owner->id, 'status' => 'open', 'opening_cash' => 100000]);
        $this->seedLegacyRows($owner);

        // Penjualan nyata nol → semua angka nol, walau ada pembayaran pre-order 900.000 di database.
        $profit = $this->getJson('/api/v1/reports/profit')->assertOk()->json();
        $this->assertSame('0.00', $profit['revenue']);

        $sales = $this->getJson('/api/v1/reports/sales')->assertOk()->json();
        $this->assertSame(0, (int) $sales['totals']['order_count']);
        $this->assertSame([], $sales['transactions']);

        $this->assertSame(0.0, (float) collect($this->getJson('/api/v1/reports/outlet-settlements')->assertOk()->json('data'))->sum('total_sales'));
        $this->getJson('/api/v1/dashboard/summary?period=today')->assertOk();
        $this->getJson('/api/v1/dashboard/attention')->assertOk();

        $summary = $this->getJson("/api/v1/sessions/{$session->id}/summary")->assertOk()->json();
        $this->assertSame(0, (int) $summary['order_count']);

        $closed = $this->postJson("/api/v1/sessions/{$session->id}/close", ['closing_cash' => 100000])->assertOk()->json();
        $this->assertEquals(100000, $closed['expected_cash']); // pembayaran pre-order lama tidak masuk kas shift

        $this->getJson('/api/v1/stock/movements')->assertOk();
    }

    public function test_the_legacy_rows_are_untouched_by_normal_use(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');
        $this->seedLegacyRows($owner);
        $before = [DB::table('events')->count(), DB::table('preorders')->count(), DB::table('payments')->where('preorder_id', '!=', null)->sum('amount')];

        $this->getJson('/api/v1/reports/profit')->assertOk();
        $this->getJson('/api/v1/customers')->assertOk();

        $after = [DB::table('events')->count(), DB::table('preorders')->count(), DB::table('payments')->where('preorder_id', '!=', null)->sum('amount')];
        $this->assertEquals($before, $after);
    }
}
