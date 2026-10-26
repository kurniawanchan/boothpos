<?php

namespace Tests\Feature\SellerSettlement;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsSettlementFixtures;
use Tests\TestCase;

/**
 * 076 (US3/FR-013/FR-015) — setelah difinalkan, angka tidak boleh diubah
 * diam-diam: koreksi lewat penyesuaian (tercatat, bertanda post_finalization)
 * yang membatalkan pengakuan seller; tidak boleh membuat total lebih kecil
 * dari yang sudah dibayar atau membalik arah pembayaran yang sudah berjalan.
 */
class SellerSettlementPostFinalizationTest extends TestCase
{
    use BuildsSettlementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpSettlementWorld();
    }

    private function adjust(int $id, string $amount, string $kind = 'adjustment')
    {
        return $this->postJson("/api/v1/settlements/{$id}/adjustments", [
            'kind' => $kind, 'label' => 'Koreksi', 'reason' => 'Salah hitung biaya', 'amount' => $amount,
        ]);
    }

    private function acknowledge(int $id): void
    {
        $this->postJson("/api/v1/settlements/{$id}/acknowledgement", ['method' => 'typed', 'signer_name' => 'Budi'])->assertCreated();
    }

    public function test_an_adjustment_after_finalization_is_flagged_and_changes_only_the_net(): void
    {
        $id = $this->finalizedSettlement(units: 3, price: 10000); // net 30.000

        $response = $this->adjust($id, '-5000')->assertCreated();

        $response->assertJsonPath('data.revenue_total', '30000.00')
            ->assertJsonPath('data.adjustments_total', '-5000.00')
            ->assertJsonPath('data.net_amount', '25000.00')
            ->assertJsonPath('data.adjustments.0.post_finalization', true)
            ->assertJsonPath('data.reconciliation.balanced', true)
            ->assertJsonPath('data.status', 'finalized');
        $this->assertSame(1, DB::table('seller_settlement_adjustments')->where('post_finalization', true)->count());
    }

    public function test_it_supersedes_the_acknowledgement_so_the_seller_must_sign_again(): void
    {
        $id = $this->finalizedSettlement(units: 3, price: 10000);
        $this->acknowledge($id);

        $this->adjust($id, '-1000')->assertCreated()
            ->assertJsonPath('data.acknowledged', false)
            ->assertJsonPath('data.display_status', 'finalized')
            ->assertJsonPath('data.acknowledgement_history.0.superseded_reason', 'adjusted');
    }

    public function test_a_deduction_cannot_be_added_after_finalization(): void
    {
        $id = $this->finalizedSettlement();

        $this->adjust($id, '1000', 'deduction')->assertStatus(409)->assertJsonPath('code', 'not_draft');
    }

    public function test_it_cannot_make_the_total_smaller_than_what_was_already_paid(): void
    {
        $id = $this->finalizedSettlement(units: 3, price: 10000); // net 30.000
        $this->postJson("/api/v1/settlements/{$id}/payments", $this->paymentPayload('20000'))->assertCreated();

        $this->adjust($id, '-15000')->assertStatus(409)->assertJsonPath('code', 'net_below_paid');
        $this->adjust($id, '-5000')->assertCreated()->assertJsonPath('data.net_amount', '25000.00')->assertJsonPath('data.balance', '5000.00');
    }

    public function test_it_cannot_flip_the_direction_once_something_was_paid(): void
    {
        $id = $this->finalizedSettlement(units: 3, price: 10000);
        $this->postJson("/api/v1/settlements/{$id}/payments", $this->paymentPayload('10000'))->assertCreated();

        $this->adjust($id, '-35000')->assertStatus(409)->assertJsonPath('code', 'direction_locked');
        $this->assertSame('30000.00', $this->getJson("/api/v1/settlements/{$id}")->json('data.net_amount'));
    }

    public function test_before_any_payment_the_direction_may_flip(): void
    {
        $id = $this->finalizedSettlement(units: 3, price: 10000);

        $this->adjust($id, '-35000')->assertCreated()
            ->assertJsonPath('data.net_amount', '-5000.00')
            ->assertJsonPath('data.direction', 'seller_pays_store');
    }

    public function test_a_zero_adjustment_is_still_refused_after_finalization(): void
    {
        $id = $this->finalizedSettlement();

        $this->adjust($id, '0')->assertStatus(422);
    }

    public function test_the_audit_log_records_the_post_finalization_adjustment(): void
    {
        $id = $this->finalizedSettlement(units: 3, price: 10000);
        $this->adjust($id, '-100')->assertCreated();

        $this->assertSame(1, \App\Models\ActivityLog::where('action', 'adjustment_added')->count());
    }
}
