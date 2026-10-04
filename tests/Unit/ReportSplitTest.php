<?php

namespace Tests\Unit;

use App\Support\ReportSplit;
use PHPUnit\Framework\TestCase;

/**
 * 033 — pembantu sisa-sen yang menjamin POS + pre-order == total.
 */
class ReportSplitTest extends TestCase
{
    public function test_cents_rounds_half_up_and_survives_float_traps(): void
    {
        $this->assertSame(101, ReportSplit::cents('1.005'));
        $this->assertSame(30, ReportSplit::cents(0.1 + 0.2));
        $this->assertSame(123450, ReportSplit::cents('1234.50'));
        $this->assertSame(0, ReportSplit::cents(null));
        $this->assertSame(0, ReportSplit::cents('0.00'));
    }

    public function test_money_formats_two_decimals_with_dot(): void
    {
        $this->assertSame('1269000.00', ReportSplit::money(126900000));
        $this->assertSame('0.05', ReportSplit::money(5));
        $this->assertSame('0.00', ReportSplit::money(0));
    }

    public function test_remainder_plus_part_always_equals_total(): void
    {
        foreach ([['1000.00', '400.00'], ['0.10', '0.20'], ['3333.335', '1111.115'], ['0', '0'], ['5000', '5000.00']] as [$total, $part]) {
            $remainder = ReportSplit::remainder($total, $part);
            $this->assertSame(
                ReportSplit::cents($total),
                ReportSplit::cents($part) + ReportSplit::cents($remainder),
                "total {$total} part {$part}"
            );
        }
    }

    public function test_remainder_of_consistent_inputs_is_never_negative(): void
    {
        $this->assertSame('600.00', ReportSplit::remainder('1000.00', '400.00'));
        $this->assertSame('0.00', ReportSplit::remainder('400.00', '400.00'));
    }
}
