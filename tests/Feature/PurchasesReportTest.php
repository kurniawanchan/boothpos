<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 006-purchase-order-and-ops (US9) — laporan pembelian di ReportController@purchases.
 */
class PurchasesReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_purchase_orders_totaled(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $vendor = Vendor::factory()->create();

        PurchaseOrder::factory()->create(['vendor_id' => $vendor->id, 'status' => 'received', 'total_amount' => 100000]);
        PurchaseOrder::factory()->create(['vendor_id' => $vendor->id, 'status' => 'draft', 'total_amount' => 50000]);

        $this->actingAs($owner, 'sanctum');
        $response = $this->getJson('/api/v1/reports/purchases');

        $response->assertOk();
        $response->assertJsonCount(2, 'rows');
        $this->assertEquals(2, $response->json('totals.po_count'));
        $this->assertEquals('150000.00', $response->json('totals.total_amount'));
    }

    public function test_purchases_report_filters_by_status_and_vendor(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $vendorA = Vendor::factory()->create();
        $vendorB = Vendor::factory()->create();

        PurchaseOrder::factory()->create(['vendor_id' => $vendorA->id, 'status' => 'received', 'total_amount' => 100000]);
        PurchaseOrder::factory()->create(['vendor_id' => $vendorB->id, 'status' => 'draft', 'total_amount' => 50000]);

        $this->actingAs($owner, 'sanctum');

        $response = $this->getJson("/api/v1/reports/purchases?vendor_id={$vendorA->id}");
        $response->assertOk()->assertJsonCount(1, 'rows');

        $response = $this->getJson('/api/v1/reports/purchases?status=draft');
        $response->assertOk()->assertJsonCount(1, 'rows');
        $this->assertEquals('50000.00', $response->json('rows.0.total_amount'));
    }

    public function test_cashier_is_forbidden_from_the_purchases_report(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $response = $this->getJson('/api/v1/reports/purchases');

        $response->assertStatus(403);
    }
}
