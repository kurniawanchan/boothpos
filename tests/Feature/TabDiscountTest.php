<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 048 / T058 — diskon tab: batas per peran, persetujuan di atas batas (terikat ke tab/baris/persen/alasan),
 * diskon dikurangkan sebelum pajak, dan DELETE menghapus diskon order.
 */
class TabDiscountTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;
    use OverrideFixtures;

    private User $cashier;

    private User $owner;

    private CashierSession $shift;

    private int $tab;

    private int $line;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->shift = $this->makeShift($this->cashier, ['opening_cash' => 0]);
        $this->tab = $this->apiOpenTab($this->shift->id)->json('id');
        $this->line = $this->apiAddLine($this->tab, $this->makeVariant(stock: 100, price: 25000)->id, 2)->json('lines.0.id');
    }

    /** Batas diskon kasir: 10 %. Pemilik memakai bawaan (tanpa batas). */
    private function cashierLimit(float $pct): void
    {
        $this->cashier->role->forceFill(['discount_limit_pct' => $pct])->save();
    }

    private function discount(array $body, ?int $tab = null)
    {
        return $this->postJson('/api/v1/tabs/'.($tab ?? $this->tab).'/discounts', $body);
    }

    public function test_a_discount_within_the_role_limit_needs_no_approval(): void
    {
        $this->cashierLimit(10);

        $this->discount(['percent' => 5, 'reason_code' => 'member', 'scope' => 'order'])
            ->assertOk()
            ->assertJsonPath('discount_amount', '2500.00')
            ->assertJsonPath('total_amount', '47500.00');
    }

    public function test_above_the_role_limit_without_a_token_is_refused_with_override_required(): void
    {
        $this->cashierLimit(10);

        $this->discount(['percent' => 15, 'reason_code' => 'member', 'scope' => 'order'])
            ->assertStatus(409)->assertJsonPath('code', 'override_required');

        $this->assertSame('0.00', (string) Order::find($this->tab)->discount_amount);
    }

    public function test_an_approval_bound_to_this_tab_and_percent_allows_the_discount_above_the_limit(): void
    {
        $this->cashierLimit(10);
        $subject = ['tab_id' => $this->tab, 'scope' => 'order', 'line_id' => null, 'percent' => 15, 'reason_code' => 'member'];
        $token = $this->overrideToken('discount', $subject, (int) $this->shift->outlet_id);

        $this->discount(['percent' => 15, 'reason_code' => 'member', 'scope' => 'order', 'override_token' => $token])
            ->assertOk()->assertJsonPath('discount_amount', '7500.00');
    }

    public function test_a_token_for_one_tab_cannot_discount_another_tab(): void
    {
        $this->cashierLimit(10);
        $other = $this->apiOpenTab($this->shift->id)->json('id');
        $subject = ['tab_id' => $this->tab, 'scope' => 'order', 'line_id' => null, 'percent' => 15, 'reason_code' => 'member'];
        $token = $this->overrideToken('discount', $subject, (int) $this->shift->outlet_id);

        $this->discount(['percent' => 15, 'reason_code' => 'member', 'scope' => 'order', 'override_token' => $token], $other)
            ->assertStatus(422)->assertJsonPath('code', 'override_mismatch');

        $this->assertSame('0.00', (string) Order::find($other)->discount_amount);
    }

    public function test_a_token_for_one_line_cannot_discount_another_line(): void
    {
        $this->cashierLimit(0);
        $otherLine = $this->apiAddLine($this->tab, $this->makeVariant(stock: 100, price: 10000)->id, 1)->json('lines.1.id');
        $subject = ['tab_id' => $this->tab, 'scope' => 'line', 'line_id' => $this->line, 'percent' => 50, 'reason_code' => 'damaged'];
        $token = $this->overrideToken('discount', $subject, (int) $this->shift->outlet_id);

        $this->discount(['percent' => 50, 'reason_code' => 'damaged', 'scope' => 'line', 'line_id' => $otherLine, 'override_token' => $token])
            ->assertStatus(422)->assertJsonPath('code', 'override_mismatch');
    }

    public function test_an_approval_is_single_use(): void
    {
        $this->cashierLimit(0);
        $subject = ['tab_id' => $this->tab, 'scope' => 'order', 'line_id' => null, 'percent' => 10, 'reason_code' => 'member'];
        $token = $this->overrideToken('discount', $subject, (int) $this->shift->outlet_id);

        $this->discount(['percent' => 10, 'reason_code' => 'member', 'scope' => 'order', 'override_token' => $token])->assertOk();
        $this->discount(['percent' => 10, 'reason_code' => 'member', 'scope' => 'order', 'override_token' => $token])
            ->assertStatus(409)->assertJsonPath('code', 'override_expired');
    }

    public function test_the_discount_is_applied_before_tax(): void
    {
        Setting::updateOrCreate(['key' => 'tax_rate'], ['value' => '10', 'type' => 'string']);
        $this->cashierLimit(100);

        // Subtotal 50.000, diskon 10 % = 5.000 → dasar pajak 45.000 → pajak 4.500 → total 49.500.
        $this->discount(['percent' => 10, 'reason_code' => 'member', 'scope' => 'order'])
            ->assertOk()
            ->assertJsonPath('discount_amount', '5000.00')
            ->assertJsonPath('tax_amount', '4500.00')
            ->assertJsonPath('total_amount', '49500.00');
    }

    public function test_a_line_discount_reduces_that_line_only(): void
    {
        $this->cashierLimit(100);

        // Baris 2 × 25.000 = 50.000; diskon 50 % pada baris itu = 25.000 → total tab 25.000.
        $this->discount(['percent' => 50, 'reason_code' => 'damaged', 'scope' => 'line', 'line_id' => $this->line])
            ->assertOk()
            ->assertJsonPath('lines.0.line_total', '25000.00')
            ->assertJsonPath('total_amount', '25000.00');
    }

    public function test_delete_removes_the_order_discount(): void
    {
        $this->cashierLimit(100);
        $this->discount(['percent' => 10, 'reason_code' => 'member', 'scope' => 'order'])->assertOk();

        $this->deleteJson("/api/v1/tabs/{$this->tab}/discounts")
            ->assertOk()->assertJsonPath('discount_amount', '0.00')->assertJsonPath('total_amount', '50000.00');
    }

    public function test_a_percent_outside_zero_to_one_hundred_is_refused(): void
    {
        $this->discount(['percent' => 0, 'reason_code' => 'member', 'scope' => 'order'])->assertStatus(422)->assertJsonValidationErrors('percent');
        $this->discount(['percent' => 101, 'reason_code' => 'member', 'scope' => 'order'])->assertStatus(422)->assertJsonValidationErrors('percent');
    }

    public function test_a_line_discount_needs_a_line(): void
    {
        $this->discount(['percent' => 10, 'reason_code' => 'member', 'scope' => 'line'])->assertStatus(422)->assertJsonValidationErrors('line_id');
    }

    public function test_a_line_of_another_tab_is_not_found(): void
    {
        $other = $this->apiOpenTab($this->shift->id)->json('id');
        $otherLine = $this->apiAddLine($other, $this->makeVariant(stock: 10, price: 5000)->id, 1)->json('lines.0.id');

        $this->discount(['percent' => 10, 'reason_code' => 'member', 'scope' => 'line', 'line_id' => $otherLine])->assertStatus(404);
    }

    public function test_a_discount_on_a_tab_whose_shift_is_closed_says_shift_required(): void
    {
        CashierSession::whereKey($this->shift->id)->update(['status' => 'closed', 'closed_at' => now()]);

        $this->discount(['percent' => 10, 'reason_code' => 'member', 'scope' => 'order'])
            ->assertStatus(409)->assertJsonPath('code', 'shift_required');
    }

    public function test_a_user_without_the_floor_menu_cannot_clear_a_discount(): void
    {
        $this->cashierLimit(100);
        $this->discount(['percent' => 10, 'reason_code' => 'member', 'scope' => 'order'])->assertOk();

        // Peran kasir tanpa menu `floor`: tidak boleh menghapus diskon yang sudah diberikan.
        $this->cashier->role->forceFill(['menu_keys' => ['pos']])->save();

        $this->deleteJson("/api/v1/tabs/{$this->tab}/discounts")->assertStatus(403);
        $this->assertSame('5000.00', (string) Order::find($this->tab)->discount_amount, 'diskon tetap ada');
    }

    public function test_clearing_a_discount_on_an_order_of_another_outlet_is_not_found(): void
    {
        $this->cashierLimit(100);
        $this->discount(['percent' => 10, 'reason_code' => 'member', 'scope' => 'order'])->assertOk();

        // Pengguna hanya ditugaskan ke outlet shift-nya (bukan semua outlet), seperti di produksi.
        $this->cashier->role->forceFill(['all_outlets' => false])->save();
        $this->cashier->outlets()->sync([$this->shift->outlet_id]);
        // Pabrik outlet menautkan pengguna non-admin ke setiap outlet baru (untuk tes lama); kembalikan penugasan
        // ke outlet shift SETELAH outlet lain dibuat, supaya tes ini menguji pengguna yang hanya punya satu outlet.
        $other = \App\Models\Outlet::factory()->create(['is_active' => true]);
        $this->cashier->outlets()->sync([$this->shift->outlet_id]);
        Order::withoutGlobalScopes()->whereKey($this->tab)->update(['outlet_id' => $other->id]);
        $this->deleteJson("/api/v1/tabs/{$this->tab}/discounts")->assertStatus(404);
    }
}
