<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 042-dashboard-revamp (T008, T009) — perintah dry-run dan migrasi pengisian tanggal bisnis.
 */
class BusinessDateBackfillTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private function legacyOrder(string $createdAt, string $status = 'completed', string $service = 'retail', ?string $updatedAt = null): int
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $session = $this->makeShift($user);
        $order = $this->makeOpenOrder($session, $user, ['status' => $status, 'service_type' => $service]);
        // Baris "lama": dibuat sebelum kolom ada → tanggal bisnis & completed_at kosong.
        DB::table('orders')->where('id', $order->id)->update([
            'created_at' => $createdAt, 'updated_at' => $updatedAt ?? $createdAt, 'business_date' => null, 'completed_at' => null,
        ]);

        return $order->id;
    }

    private function runBackfill(): void
    {
        (require base_path('database/migrations/2026_11_03_000003_backfill_business_dates.php'))->up();
    }

    public function test_the_backfill_uses_the_store_timezone_and_is_idempotent(): void
    {
        $late = $this->legacyOrder('2026-10-10 19:00:00');   // 02.00 WIB tanggal 11
        $day = $this->legacyOrder('2026-10-10 03:00:00');    // 10.00 WIB tanggal 10

        $this->runBackfill();
        $first = DB::table('orders')->whereIn('id', [$late, $day])->pluck('business_date', 'id')->all();
        $this->runBackfill();                                // jalankan ulang → tidak berubah

        $this->assertSame('2026-10-11', $first[$late]);
        $this->assertSame('2026-10-10', $first[$day]);
        $this->assertSame($first, DB::table('orders')->whereIn('id', [$late, $day])->pluck('business_date', 'id')->all());
    }

    public function test_completed_at_follows_the_rules_for_retail_tabs_and_unfinished_orders(): void
    {
        $retail = $this->legacyOrder('2026-10-10 03:00:00', 'completed', 'retail', '2026-10-10 09:00:00');
        $tab = $this->legacyOrder('2026-10-10 03:00:00', 'completed', 'dine_in', '2026-10-10 09:00:00');
        $open = $this->legacyOrder('2026-10-10 03:00:00', 'open', 'dine_in', '2026-10-10 09:00:00');
        $voided = $this->legacyOrder('2026-10-10 03:00:00', 'voided', 'retail', '2026-10-10 09:00:00');

        $this->runBackfill();
        $at = DB::table('orders')->whereIn('id', [$retail, $tab, $open, $voided])->pluck('completed_at', 'id');

        $this->assertSame('2026-10-10 03:00:00', $at[$retail]);
        $this->assertSame('2026-10-10 09:00:00', $at[$tab]);
        $this->assertNull($at[$open]);
        $this->assertNull($at[$voided]);
    }

    public function test_payments_and_shifts_are_filled_too(): void
    {
        $order = $this->legacyOrder('2026-10-10 03:00:00');
        $paymentId = DB::table('payments')->insertGetId([
            'order_id' => $order, 'method' => 'cash', 'purpose' => 'full', 'amount' => 1000, 'verification' => 'verified',
            'paid_at' => '2026-10-10 19:30:00', 'data_mode' => 'live', 'created_at' => '2026-10-10 19:30:00', 'updated_at' => '2026-10-10 19:30:00',
        ]);
        DB::table('cashier_sessions')->update(['business_date' => null, 'opened_at' => '2026-10-10 19:10:00']);

        $this->runBackfill();

        $this->assertSame('2026-10-11', DB::table('payments')->where('id', $paymentId)->value('business_date'));
        $this->assertSame('2026-10-11', DB::table('cashier_sessions')->value('business_date'));
    }

    public function test_the_dry_run_lists_the_orders_that_would_move_and_writes_nothing(): void
    {
        $this->legacyOrder('2026-10-10 19:00:00');           // pindah 10 → 11
        $this->legacyOrder('2026-10-10 03:00:00');           // tetap
        $this->legacyOrder('2026-10-10 03:00:00', 'completed', 'dine_in', '2026-10-12 09:00:00'); // updated_at jauh

        $this->artisan('app:business-date-dry-run')
            ->expectsOutputToContain('Order yang pindah hari (tanggal UTC lama → tanggal bisnis baru): 1')
            ->expectsOutputToContain('berjarak > 12 jam: 1')
            ->expectsOutputToContain('Tidak ada yang ditulis.')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('orders')->whereNotNull('business_date')->count());
    }

    public function test_the_dry_run_reports_the_timezone_in_use(): void
    {
        Setting::updateOrCreate(['key' => 'store_timezone'], ['value' => 'Asia/Makassar', 'type' => 'string']);

        $this->artisan('app:business-date-dry-run')->expectsOutputToContain('Asia/Makassar')->assertSuccessful();
    }
}
