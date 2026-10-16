<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 048 / T037 — pembayaran tab di shift yang sudah tertutup ditolak dengan kode mesin `shift_required`
 * (409), supaya terminal menampilkan tindakan yang tepat tanpa mengurai kalimat galat.
 */
class ShiftRequiredTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    public function test_paying_a_tab_after_its_shift_closed_says_shift_required(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');
        $shift = $this->makeShift($cashier);
        $tab = $this->apiOpenTab($shift->id)->json('id');
        $variant = $this->makeVariant(stock: 10, price: 20000);
        $this->apiAddLine($tab, $variant->id, 1)->assertCreated();

        CashierSession::whereKey($shift->id)->update(['status' => 'closed', 'closed_at' => now()]);

        $this->apiPay($tab, [['method' => 'cash', 'amount' => 20000]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'shift_required');

        $this->assertSame('open', Order::find($tab)->status, 'tab tidak boleh berubah saat pembayaran ditolak');
    }

    public function test_closing_with_an_open_tab_answers_with_the_open_tabs_code(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');
        $shift = $this->makeShift($cashier);
        $this->apiOpenTab($shift->id)->assertCreated();

        $this->postJson("/api/v1/sessions/{$shift->id}/close", ['counted_by_denomination' => []])
            ->assertStatus(409)
            ->assertJsonPath('code', 'open_tabs')
            ->assertJsonCount(1, 'open_tabs');

        $this->assertSame('open', $shift->fresh()->status);
    }
}
