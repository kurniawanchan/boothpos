<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 048 / T059 — bagi rata tagihan (pratinjau). Bagian = bagian rupiah utuh, sisa pembulatan ke bagian terakhir,
 * jumlah bagian = tagihan termasuk pajak, dan TIDAK ada pembayaran atau perubahan tab.
 */
class TabSplitEqualTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;

    private CashierSession $shift;

    private int $tab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->shift = $this->makeShift($this->cashier, ['opening_cash' => 0]);
        $this->tab = $this->apiOpenTab($this->shift->id)->json('id');
    }

    private function split(array $body)
    {
        return $this->postJson("/api/v1/tabs/{$this->tab}/split", ['mode' => 'equal'] + $body);
    }

    public function test_a_tax_inclusive_bill_splits_into_equal_rupiah_parts(): void
    {
        Setting::updateOrCreate(['key' => 'tax_rate'], ['value' => '10', 'type' => 'string']);
        $this->apiAddLine($this->tab, $this->makeVariant(stock: 10, price: 30000)->id, 2)->assertCreated();

        // Subtotal 60.000 + pajak 10 % = 66.000 → 3 bagian × 22.000.
        $this->split(['parts' => 3])
            ->assertOk()
            ->assertJsonPath('total', '66000.00')
            ->assertJsonPath('parts.0.amount', '22000.00')
            ->assertJsonPath('parts.1.amount', '22000.00')
            ->assertJsonPath('parts.2.amount', '22000.00');
    }

    public function test_the_remainder_goes_to_the_last_part(): void
    {
        $this->apiAddLine($this->tab, $this->makeVariant(stock: 10, price: 10000)->id, 1)->assertCreated();

        // 10.000 ÷ 3 = 3.333 + 3.333 + sisa 1 → bagian terakhir 3.334; jumlahnya tepat 10.000.
        $res = $this->split(['parts' => 3])->assertOk()
            ->assertJsonPath('parts.0.amount', '3333.00')
            ->assertJsonPath('parts.1.amount', '3333.00')
            ->assertJsonPath('parts.2.amount', '3334.00');

        $sum = array_sum(array_map(fn ($p) => (float) $p['amount'], $res->json('parts')));
        $this->assertEqualsWithDelta(10000.0, $sum, 0.001);
    }

    public function test_two_parts_of_a_taxed_bill(): void
    {
        Setting::updateOrCreate(['key' => 'tax_rate'], ['value' => '10', 'type' => 'string']);
        $this->apiAddLine($this->tab, $this->makeVariant(stock: 10, price: 30000)->id, 2)->assertCreated();

        $this->split(['parts' => 2])->assertOk()->assertJsonPath('parts.0.amount', '33000.00')->assertJsonPath('parts.1.amount', '33000.00');
    }

    public function test_the_preview_records_no_payment_and_leaves_the_tab_open_and_unchanged(): void
    {
        $this->apiAddLine($this->tab, $this->makeVariant(stock: 10, price: 10000)->id, 1)->assertCreated();

        $this->split(['parts' => 3])->assertOk();

        $this->assertSame(0, Payment::count(), 'pratinjau bagian tidak boleh mencatat pembayaran');
        $tab = Order::find($this->tab);
        $this->assertSame('open', $tab->status);
        $this->assertEqualsWithDelta(10000.0, (float) $tab->total_amount, 0.001);
    }

    public function test_one_part_or_six_parts_are_refused(): void
    {
        $this->apiAddLine($this->tab, $this->makeVariant(stock: 10, price: 10000)->id, 1)->assertCreated();

        $this->split(['parts' => 1])->assertStatus(422)->assertJsonValidationErrors('parts');
        $this->split(['parts' => 6])->assertStatus(422)->assertJsonValidationErrors('parts');
    }

    public function test_an_empty_or_closed_tab_is_not_split(): void
    {
        $this->split(['parts' => 2])->assertStatus(409);
    }
}
