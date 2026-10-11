<?php

namespace Tests\Unit;

use App\Support\RecipeCost;
use PHPUnit\Framework\TestCase;

/** 043 (T074) — rumus biaya resep, dicocokkan dengan hitungan tangan independen (SC-005). */
class RecipeCostTest extends TestCase
{
    public function test_line_cost_divides_by_the_surviving_share(): void
    {
        // 0,5 × 40.000 ÷ (1 − 20%) = 25.000
        $this->assertSame(25000.0, RecipeCost::line(0.5, 20, 40000));
        $this->assertSame(10000.0, RecipeCost::line(2, 0, 5000));
        $this->assertNull(RecipeCost::line(1, 0, null));
    }

    public function test_portion_cost_food_cost_and_margin_match_a_hand_calculation(): void
    {
        // Baris: 2 × 5.000 = 10.000; 0,5 susut 20% × 40.000 = 25.000 → jumlah 35.000; yield 2 → porsi 17.500.
        $r = RecipeCost::compute([
            ['quantity' => 2, 'shrinkage_pct' => 0, 'unit_cost' => 5000],
            ['quantity' => '0.5000', 'shrinkage_pct' => '20.00', 'unit_cost' => '40000'],
        ], 2.0, 50000);

        $this->assertSame([10000.0, 25000.0], $r['line_costs']);
        $this->assertSame(17500.0, $r['portion_cost']);
        $this->assertFalse($r['incomplete']);
        $this->assertSame(35.0, $r['food_cost_pct']);
        $this->assertSame(32500.0, $r['gross_margin']);
    }

    public function test_a_line_without_cost_marks_the_result_incomplete_and_never_becomes_zero_margin(): void
    {
        $r = RecipeCost::compute([
            ['quantity' => 1, 'shrinkage_pct' => 0, 'unit_cost' => 3000],
            ['quantity' => 1, 'shrinkage_pct' => 0, 'unit_cost' => null],
        ], 1.0, 10000);

        $this->assertTrue($r['incomplete']);
        $this->assertNull($r['food_cost_pct']);
        $this->assertNull($r['gross_margin']);
        $this->assertSame(3000.0, $r['portion_cost']);   // hanya yang berbiaya; UI menandainya tidak lengkap
    }

    public function test_no_base_price_gives_no_percentage(): void
    {
        $r = RecipeCost::compute([['quantity' => 1, 'shrinkage_pct' => 0, 'unit_cost' => 100]], 1.0, null);

        $this->assertNull($r['food_cost_pct']);
        $this->assertSame(100.0, $r['portion_cost']);
    }
}
