<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Preorder;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Spec 061 (US4) — `app:settle-paid-arrived-preorders`: koreksi sekali pakai untuk pre-order yang
 * macet di "arrived" padahal sudah lunas (dulu "settled" hanya tercapai lewat pembayaran SESUDAH
 * tiba). Dry-run bawaan, idempoten, DEMO dan LIVE, tidak menyentuh stok/pembayaran/total.
 */
class SettlePaidArrivedPreordersCommandTest extends TestCase
{
    use RefreshDatabase;

    private const CMD = 'app:settle-paid-arrived-preorders';

    private const SECRET_NAME = 'Pelanggan Rahasia Uji';

    private int $seq = 0;

    /**
     * Pre-order langsung lewat model di mode tertentu (HasDataMode menandai barisnya).
     *
     * @param  array<int, array{amount: float, verification?: string}>  $payments
     */
    private function preorder(string $mode, string $status, float $total, array $payments): Preorder
    {
        return ModeGate::runAs($mode, function () use ($status, $total, $payments, $mode) {
            $customer = Customer::factory()->create(['name' => self::SECRET_NAME]);
            $pre = Preorder::create([
                'preorder_number' => 'PO-TEST-'.strtoupper($mode).'-'.(++$this->seq),
                'customer_id' => $customer->id,
                'user_id' => User::factory()->create(['role' => 'cashier'])->id,
                'status' => $status,
                'fulfillment' => 'pickup',
                'subtotal' => $total, 'shipping_cost' => 0, 'discount' => 0,
                'total_amount' => $total,
                'paid_amount' => array_sum(array_column($payments, 'amount')),
            ]);
            foreach ($payments as $p) {
                Payment::create([
                    'preorder_id' => $pre->id, 'method' => 'cash', 'purpose' => 'full',
                    'amount' => $p['amount'], 'verification' => $p['verification'] ?? 'verified', 'paid_at' => now(),
                ]);
            }

            return $pre;
        });
    }

    private function statusOf(Preorder $pre): string
    {
        return Preorder::withoutGlobalScopes()->findOrFail($pre->id)->status;
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'username' => 'pemilik']);
    }

    public function test_dry_run_lists_numbers_and_writes_nothing(): void
    {
        $stuck = $this->preorder('live', 'arrived', 25000, [['amount' => 25000]]);

        $code = Artisan::call(self::CMD);

        $output = Artisan::output(); // satu kali: output() mengosongkan buffer

        $this->assertSame(0, $code);
        $this->assertStringContainsString($stuck->preorder_number, $output);
        $this->assertStringContainsStringIgnoringCase('dry-run', $output);
        $this->assertSame('arrived', $this->statusOf($stuck));
        $this->assertSame(0, ActivityLog::where('action', 'preorder_status_corrected')->count());
    }

    public function test_apply_settles_stuck_rows_in_both_modes_and_logs_each_without_touching_money(): void
    {
        $owner = $this->owner();
        $live = $this->preorder('live', 'arrived', 25000, [['amount' => 25000]]);
        $demo = $this->preorder('demo', 'arrived', 100000, [['amount' => 60000], ['amount' => 40000]]);

        $code = Artisan::call(self::CMD, ['--apply' => true, '--by' => 'pemilik']);

        $this->assertSame(0, $code);
        $this->assertSame('settled', $this->statusOf($live));
        $this->assertSame('settled', $this->statusOf($demo));

        $logs = ActivityLog::where('action', 'preorder_status_corrected')->get();
        $this->assertCount(2, $logs);
        foreach ($logs as $log) {
            $this->assertSame('preorder', $log->entity_type);
            $this->assertSame($owner->id, $log->user_id);
            $this->assertSame('arrived', $log->old_values['status']);
            $this->assertSame('settled', $log->new_values['status']);
        }

        // Uang dan stok tidak berubah.
        $this->assertSame('25000.00', Preorder::withoutGlobalScopes()->find($live->id)->total_amount);
        $this->assertSame('25000.00', Preorder::withoutGlobalScopes()->find($live->id)->paid_amount);
        $this->assertSame(0, \DB::table('stock_movements')->count());
        $this->assertSame(3, Payment::withoutGlobalScopes()->count());
    }

    public function test_rows_that_are_not_stuck_are_left_alone(): void
    {
        $this->owner();
        $partial = $this->preorder('live', 'arrived', 100000, [['amount' => 40000]]);
        $unpaid = $this->preorder('live', 'arrived', 100000, []);
        $rejectedOnly = $this->preorder('live', 'arrived', 50000, [['amount' => 50000, 'verification' => 'rejected']]);
        $settled = $this->preorder('live', 'settled', 100000, [['amount' => 100000]]);
        $dpPaid = $this->preorder('live', 'dp_paid', 100000, [['amount' => 100000]]);
        $cancelled = $this->preorder('live', 'cancelled', 100000, [['amount' => 100000]]);

        Artisan::call(self::CMD, ['--apply' => true, '--by' => 'pemilik']);

        $this->assertSame('arrived', $this->statusOf($partial));
        $this->assertSame('arrived', $this->statusOf($unpaid));
        $this->assertSame('arrived', $this->statusOf($rejectedOnly)); // pembayaran ditolak tidak dihitung
        $this->assertSame('settled', $this->statusOf($settled));
        $this->assertSame('dp_paid', $this->statusOf($dpPaid));
        $this->assertSame('cancelled', $this->statusOf($cancelled));
        $this->assertSame(0, ActivityLog::where('action', 'preorder_status_corrected')->count());
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->owner();
        $this->preorder('live', 'arrived', 25000, [['amount' => 25000]]);
        Artisan::call(self::CMD, ['--apply' => true, '--by' => 'pemilik']);
        $this->assertSame(1, ActivityLog::where('action', 'preorder_status_corrected')->count());

        $code = Artisan::call(self::CMD, ['--apply' => true, '--by' => 'pemilik']);

        $this->assertSame(0, $code);
        $this->assertSame(1, ActivityLog::where('action', 'preorder_status_corrected')->count());
    }

    public function test_apply_requires_an_owner_or_admin_actor_and_writes_nothing_otherwise(): void
    {
        $stuck = $this->preorder('live', 'arrived', 25000, [['amount' => 25000]]);
        User::factory()->create(['role' => 'cashier', 'username' => 'kasir-uji']);

        $missing = Artisan::call(self::CMD, ['--apply' => true]);
        $unknown = Artisan::call(self::CMD, ['--apply' => true, '--by' => 'tidak-ada']);
        $cashier = Artisan::call(self::CMD, ['--apply' => true, '--by' => 'kasir-uji']);

        $this->assertNotSame(0, $missing);
        $this->assertNotSame(0, $unknown);
        $this->assertNotSame(0, $cashier);
        $this->assertSame('arrived', $this->statusOf($stuck));
        $this->assertSame(0, ActivityLog::where('action', 'preorder_status_corrected')->count());
    }

    public function test_output_never_contains_customer_data(): void
    {
        $this->owner();
        $this->preorder('live', 'arrived', 25000, [['amount' => 25000]]);

        Artisan::call(self::CMD);
        $dry = Artisan::output();
        Artisan::call(self::CMD, ['--apply' => true, '--by' => 'pemilik']);
        $applied = Artisan::output();

        $this->assertStringNotContainsString(self::SECRET_NAME, $dry);
        $this->assertStringNotContainsString(self::SECRET_NAME, $applied);
        $this->assertStringNotContainsString(self::SECRET_NAME, json_encode(ActivityLog::all()->toArray()));
    }
}
