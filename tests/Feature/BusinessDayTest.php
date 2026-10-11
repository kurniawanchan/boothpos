<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 042-dashboard-revamp (T007) — tanggal bisnis dari zona waktu toko + jam batas hari.
 */
class BusinessDayTest extends TestCase
{
    use RefreshDatabase;

    private function setting(string $key, string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value, 'type' => 'string']);
    }

    public function test_defaults_are_jakarta_and_midnight(): void
    {
        $this->assertSame('Asia/Jakarta', BusinessDay::timezone());
        $this->assertSame('00:00', BusinessDay::cutoff());
    }

    public function test_a_sale_at_2am_wib_belongs_to_that_local_day_not_the_previous_utc_day(): void
    {
        // 02:00 WIB = 19:00 UTC hari sebelumnya.
        $instant = CarbonImmutable::parse('2026-10-10 19:00:00', 'UTC');

        $this->assertSame('2026-10-11', BusinessDay::dateFor($instant));
    }

    public function test_the_cutoff_moves_the_day_boundary(): void
    {
        $this->setting('business_day_cutoff', '04:00');

        // 03:59 WIB masih hari sebelumnya, 04:00 WIB hari baru.
        $this->assertSame('2026-10-10', BusinessDay::dateFor(CarbonImmutable::parse('2026-10-11 03:59:00', 'Asia/Jakarta')));
        $this->assertSame('2026-10-11', BusinessDay::dateFor(CarbonImmutable::parse('2026-10-11 04:00:00', 'Asia/Jakarta')));
    }

    public function test_the_end_of_day_boundary_with_no_cutoff(): void
    {
        $this->assertSame('2026-10-10', BusinessDay::dateFor(CarbonImmutable::parse('2026-10-10 23:59:59', 'Asia/Jakarta')));
        $this->assertSame('2026-10-11', BusinessDay::dateFor(CarbonImmutable::parse('2026-10-11 00:00:00', 'Asia/Jakarta')));
    }

    public function test_bounds_are_half_open_utc_and_match_dateFor(): void
    {
        [$start, $end] = BusinessDay::bounds('2026-10-11');

        $this->assertSame('2026-10-10 17:00:00', $start->format('Y-m-d H:i:s')); // 00:00 WIB
        $this->assertSame('2026-10-11 17:00:00', $end->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-11', BusinessDay::dateFor($start));
        $this->assertSame('2026-10-12', BusinessDay::dateFor($end)); // batas atas TIDAK termasuk
        $this->assertSame('2026-10-11', BusinessDay::dateFor($end->subSecond()));
    }

    public function test_bounds_honour_the_cutoff(): void
    {
        $this->setting('business_day_cutoff', '04:00');

        [$start, $end] = BusinessDay::bounds('2026-10-11');

        $this->assertSame('2026-10-10 21:00:00', $start->format('Y-m-d H:i:s')); // 04:00 WIB
        $this->assertSame('2026-10-11 21:00:00', $end->format('Y-m-d H:i:s'));
    }

    public function test_a_dst_zone_has_a_23_hour_day_and_bounds_stay_correct(): void
    {
        $this->setting('store_timezone', 'Europe/Amsterdam');

        // 2026-03-29: jam maju di Eropa → hari itu hanya 23 jam.
        [$start, $end] = BusinessDay::bounds('2026-03-29');

        $this->assertSame(23 * 3600, $end->getTimestamp() - $start->getTimestamp());
        $this->assertSame('2026-03-29', BusinessDay::dateFor($start));
        $this->assertSame('2026-03-30', BusinessDay::dateFor($end));
    }

    public function test_an_unknown_timezone_or_malformed_cutoff_falls_back_to_the_defaults(): void
    {
        $this->setting('store_timezone', 'Mars/Olympus');
        $this->setting('business_day_cutoff', '25:99');

        $this->assertSame('Asia/Jakarta', BusinessDay::timezone());
        $this->assertSame('00:00', BusinessDay::cutoff());
    }

    public function test_today_uses_the_store_clock(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 18:30:00'); // UTC → 01:30 WIB tanggal 11
        try {
            $this->assertSame('2026-10-11', BusinessDay::today());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
