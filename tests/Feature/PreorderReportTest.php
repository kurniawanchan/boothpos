<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Event;
use App\Models\Payment;
use App\Models\Preorder;
use App\Models\Setting;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 010-split-payment-preorder-reports (US6, T033) — GET /reports/preorders:
 * agregasi status x kelengkapan pembayaran, dihitung dari SUM(payments)
 * hidup (bukan cache preorders.paid_amount) per research.md R6.
 */
class PreorderReportTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsOwner(): User
    {
        $user = User::factory()->create(['role' => 'owner']);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function makePreorder(array $attrs, User $user, Customer $customer): Preorder
    {
        return Preorder::create(array_merge([
            'preorder_number' => 'PO-'.uniqid(),
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'subtotal' => $attrs['total_amount'],
            'shipping_cost' => 0,
        ], $attrs));
    }

    public function test_grouping_and_totals_across_statuses_and_payment_buckets(): void
    {
        $owner = $this->actingAsOwner();
        $customer = Customer::factory()->create();
        $event = Event::factory()->create();

        // unpaid: status ordered, no payment at all.
        $unpaid = $this->makePreorder([
            'event_id' => $event->id, 'status' => 'ordered', 'total_amount' => 100000,
        ], $owner, $customer);

        // partial: status dp_paid, 40000 collected of 100000.
        $partial = $this->makePreorder([
            'event_id' => $event->id, 'status' => 'dp_paid', 'total_amount' => 100000,
        ], $owner, $customer);
        Payment::create([
            'preorder_id' => $partial->id, 'method' => 'cash', 'purpose' => 'down_payment',
            'amount' => 40000, 'verification' => 'verified', 'paid_at' => now(),
        ]);

        // paid: status handed_over, fully collected 200000 of 200000.
        $paid = $this->makePreorder([
            'event_id' => $event->id, 'status' => 'handed_over', 'total_amount' => 200000,
        ], $owner, $customer);
        Payment::create([
            'preorder_id' => $paid->id, 'method' => 'cash', 'purpose' => 'down_payment',
            'amount' => 200000, 'verification' => 'verified', 'paid_at' => now(),
        ]);

        // cancelled preorder must still appear in status breakdown even
        // though it never had a payment (unpaid bucket).
        $cancelled = $this->makePreorder([
            'event_id' => $event->id, 'status' => 'cancelled', 'total_amount' => 50000,
        ], $owner, $customer);

        // rejected payment must be excluded from amount_collected, so this
        // preorder stays 'unpaid' despite having a payments row.
        $rejectedPayment = $this->makePreorder([
            'event_id' => $event->id, 'status' => 'ordered', 'total_amount' => 100000,
        ], $owner, $customer);
        Payment::create([
            'preorder_id' => $rejectedPayment->id, 'method' => 'cash', 'purpose' => 'down_payment',
            'amount' => 100000, 'verification' => 'rejected', 'paid_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/reports/preorders?event_id={$event->id}");

        $response->assertOk();
        $rows = collect($response->json('rows'))->keyBy(fn ($r) => $r['status'].'|'.$r['payment_completeness']);

        $orderedUnpaid = $rows->get('ordered|unpaid');
        $this->assertNotNull($orderedUnpaid);
        $this->assertSame(2, $orderedUnpaid['preorder_count']); // $unpaid + $rejectedPayment
        $this->assertSame('200000.00', $orderedUnpaid['total_order_value']);
        $this->assertSame('0.00', $orderedUnpaid['total_collected']);
        $this->assertSame('200000.00', $orderedUnpaid['total_outstanding']);

        $dpPartial = $rows->get('dp_paid|partial');
        $this->assertNotNull($dpPartial);
        $this->assertSame(1, $dpPartial['preorder_count']);
        $this->assertSame('100000.00', $dpPartial['total_order_value']);
        $this->assertSame('40000.00', $dpPartial['total_collected']);
        $this->assertSame('60000.00', $dpPartial['total_outstanding']);

        $handedOverPaid = $rows->get('handed_over|paid');
        $this->assertNotNull($handedOverPaid);
        $this->assertSame(1, $handedOverPaid['preorder_count']);
        $this->assertSame('200000.00', $handedOverPaid['total_order_value']);
        $this->assertSame('200000.00', $handedOverPaid['total_collected']);
        $this->assertSame('0.00', $handedOverPaid['total_outstanding']);

        $cancelledUnpaid = $rows->get('cancelled|unpaid');
        $this->assertNotNull($cancelledUnpaid);
        $this->assertSame(1, $cancelledUnpaid['preorder_count']);
        $this->assertSame('50000.00', $cancelledUnpaid['total_order_value']);
        $this->assertSame('0.00', $cancelledUnpaid['total_collected']);
    }

    public function test_event_filter_narrows_results_to_one_event(): void
    {
        $owner = $this->actingAsOwner();
        $customer = Customer::factory()->create();
        $eventA = Event::factory()->create();
        $eventB = Event::factory()->create();

        $this->makePreorder(['event_id' => $eventA->id, 'status' => 'ordered', 'total_amount' => 100000], $owner, $customer);
        $this->makePreorder(['event_id' => $eventB->id, 'status' => 'ordered', 'total_amount' => 999999], $owner, $customer);

        $response = $this->getJson("/api/v1/reports/preorders?event_id={$eventA->id}");

        $response->assertOk();
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame('100000.00', $rows[0]['total_order_value']);
    }

    public function test_data_mode_isolation_between_demo_and_live(): void
    {
        $owner = $this->actingAsOwner();
        $customer = Customer::factory()->create();
        $event = Event::factory()->create();

        ModeGate::runAs('live', function () use ($owner, $customer, $event) {
            $this->makePreorder(['event_id' => $event->id, 'status' => 'ordered', 'total_amount' => 111000], $owner, $customer);
        });
        ModeGate::runAs('demo', function () use ($owner, $customer, $event) {
            $this->makePreorder(['event_id' => $event->id, 'status' => 'ordered', 'total_amount' => 999000], $owner, $customer);
        });

        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'live', 'type' => 'string', 'group' => 'system']);
        $liveResponse = $this->getJson('/api/v1/reports/preorders');
        $liveResponse->assertOk();
        $liveRows = collect($liveResponse->json('rows'));
        $this->assertSame(1, $liveRows->sum('preorder_count'));
        $this->assertSame('111000.00', $liveRows->first()['total_order_value']);

        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'demo', 'type' => 'string', 'group' => 'system']);
        $demoResponse = $this->getJson('/api/v1/reports/preorders');
        $demoResponse->assertOk();
        $demoRows = collect($demoResponse->json('rows'));
        $this->assertSame(1, $demoRows->sum('preorder_count'));
        $this->assertSame('999000.00', $demoRows->first()['total_order_value']);
    }
}
