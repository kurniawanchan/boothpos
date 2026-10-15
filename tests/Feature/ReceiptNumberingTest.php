<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Outlet;
use App\Services\ReceiptNumbering;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 048 / T028 (B12) — nomor struk per terminal dan antrean per outlet per hari. */
class ReceiptNumberingTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $code = 'T01'): Device
    {
        $outlet = Outlet::first() ?? Outlet::factory()->create();
        $d = Device::forceCreate(['outlet_id' => $outlet->id, 'code' => $code, 'name' => 'x']);
        DB::table('terminal_counters')->insert(['device_id' => $d->id, 'receipt_seq' => 0]);

        return $d;
    }

    public function test_receipt_numbers_are_sequential_per_terminal_without_gaps(): void
    {
        $n = app(ReceiptNumbering::class);
        $a = $this->device('T01');
        $b = $this->device('T02');

        $this->assertSame('T01-000001', $n->receiptFor($a));
        $this->assertSame('T01-000002', $n->receiptFor($a));
        $this->assertSame('T02-000001', $n->receiptFor($b));
        $this->assertSame('T01-000003', $n->receiptFor($a));
    }

    public function test_a_missing_counter_row_is_created_not_a_crash(): void
    {
        $outlet = Outlet::first() ?? Outlet::factory()->create();
        $d = Device::forceCreate(['outlet_id' => $outlet->id, 'code' => 'T09', 'name' => 'x']);

        $this->assertSame('T09-000001', app(ReceiptNumbering::class)->receiptFor($d));
    }

    public function test_queue_numbers_restart_each_business_day_and_are_per_outlet(): void
    {
        $n = app(ReceiptNumbering::class);
        $o1 = Outlet::first() ?? Outlet::factory()->create();
        $o2 = Outlet::factory()->create();

        $this->assertSame('A-001', $n->queueFor($o1->id, '2026-10-31'));
        $this->assertSame('A-002', $n->queueFor($o1->id, '2026-10-31'));
        $this->assertSame('A-001', $n->queueFor($o2->id, '2026-10-31'));
        $this->assertSame('A-001', $n->queueFor($o1->id, '2026-11-01'));
    }

    public function test_the_counter_rolls_back_with_the_caller_transaction(): void
    {
        $n = app(ReceiptNumbering::class);
        $d = $this->device();

        try {
            DB::transaction(function () use ($n, $d) {
                $n->receiptFor($d);
                throw new \RuntimeException('batal');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame('T01-000001', $n->receiptFor($d));
    }
}
