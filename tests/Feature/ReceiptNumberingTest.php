<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Outlet;
use App\Services\ReceiptNumbering;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 048 / T028 (B12) — nomor struk dan antrean per outlet per hari. 064: nomor struk urut polos per OUTLET, tanpa terminal. */
class ReceiptNumberingTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_numbers_are_plain_sequential_per_outlet_without_gaps(): void
    {
        $n = app(ReceiptNumbering::class);
        $o1 = Outlet::first() ?? Outlet::factory()->create();
        $o2 = Outlet::factory()->create();

        $this->assertSame('000001', $n->receiptFor($o1->id));
        $this->assertSame('000002', $n->receiptFor($o1->id));
        $this->assertSame('000001', $n->receiptFor($o2->id), 'outlet lain mulai dari 000001');
        $this->assertSame('000003', $n->receiptFor($o1->id));
    }

    public function test_terminals_of_one_outlet_share_the_outlet_sequence(): void
    {
        $outlet = Outlet::first() ?? Outlet::factory()->create();
        Device::forceCreate(['outlet_id' => $outlet->id, 'name' => 'Kasir Depan']);
        Device::forceCreate(['outlet_id' => $outlet->id, 'name' => 'Kasir Belakang']);
        $n = app(ReceiptNumbering::class);

        // Nomor tidak lagi memuat terminal: dua terminal satu outlet satu deret.
        $this->assertSame('000001', $n->receiptFor($outlet->id));
        $this->assertSame('000002', $n->receiptFor($outlet->id));
    }

    public function test_a_missing_counter_row_is_created_not_a_crash(): void
    {
        $outlet = Outlet::first() ?? Outlet::factory()->create();
        $this->assertSame(0, DB::table('outlet_receipt_counters')->where('outlet_id', $outlet->id)->count());

        $this->assertSame('000001', app(ReceiptNumbering::class)->receiptFor($outlet->id));
        $this->assertSame(1, DB::table('outlet_receipt_counters')->where('outlet_id', $outlet->id)->count());
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
        $outlet = Outlet::first() ?? Outlet::factory()->create();

        try {
            DB::transaction(function () use ($n, $outlet) {
                $n->receiptFor($outlet->id);
                throw new \RuntimeException('batal');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame('000001', $n->receiptFor($outlet->id));
    }
}
