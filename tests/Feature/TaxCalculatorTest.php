<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\TaxCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 048 / T026 (B8) — satu rumus pajak eksklusif: setelah diskon, dibulatkan per order. */
class TaxCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_rate_is_the_default_so_existing_installs_do_not_change(): void
    {
        $this->assertSame(['tax' => 0.0, 'rate' => 0.0], TaxCalculator::compute(60_000));
    }

    public function test_ten_percent_of_sixty_thousand(): void
    {
        $this->assertSame(6_000.0, TaxCalculator::compute(60_000, 10)['tax']);
    }

    public function test_rounding_is_half_up_to_whole_rupiah(): void
    {
        $this->assertSame(1_106.0, TaxCalculator::compute(10_050, 11)['tax']);   // 1105.5 → 1106
    }

    public function test_negative_or_zero_base_has_no_tax(): void
    {
        $this->assertSame(0.0, TaxCalculator::compute(-5_000, 10)['tax']);
        $this->assertSame(0.0, TaxCalculator::compute(0, 10)['tax']);
    }

    public function test_rate_comes_from_settings(): void
    {
        Setting::updateOrCreate(['key' => 'tax_rate'], ['value' => '11', 'type' => 'string']);

        $this->assertSame(['tax' => 1_100.0, 'rate' => 11.0], TaxCalculator::compute(10_000));
    }
}
