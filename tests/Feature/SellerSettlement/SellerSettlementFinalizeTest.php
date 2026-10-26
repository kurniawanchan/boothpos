<?php

namespace Tests\Feature\SellerSettlement;

use App\Models\ActivityLog;
use App\Models\SellerSettlement;
use App\Services\ActivityLogger;
use App\Services\PreorderService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSettlementFixtures;
use Tests\TestCase;

/**
 * 076 (US2) — finalisasi: gerbang rekonsiliasi, snapshot beku, nomor unik,
 * isyarat "sumber berubah", reopen, dan jejak audit di transaksi yang sama.
 */
class SellerSettlementFinalizeTest extends TestCase
{
    use BuildsSettlementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSettlementWorld();
    }

    public function test_finalize_freezes_the_statement_assigns_a_number_and_proves_the_balance(): void
    {
        [$a, $va] = $this->seller('AAA', 10000);
        $this->posSale([[$va, 4]]);
        $id = $this->createDraft($a);
        $this->postJson("/api/v1/settlements/{$id}/adjustments", [
            'kind' => 'deduction', 'label' => 'Biaya booth', 'reason' => 'R', 'amount' => '5000',
        ])->assertCreated();

        $response = $this->postJson("/api/v1/settlements/{$id}/finalize")->assertOk();

        $response->assertJsonPath('data.status', 'finalized')
            ->assertJsonPath('data.display_status', 'finalized')
            ->assertJsonPath('data.net_amount', '35000.00')
            ->assertJsonPath('data.reconciliation.balanced', true)
            ->assertJsonPath('data.reconciliation.difference', '0.00')
            ->assertJsonPath('data.reconciliation.expected_net', '35000.00');
        $this->assertMatchesRegularExpression('/^STL-\d{8}-\d{4}$/', $response->json('data.number'));
        $this->assertNotNull($response->json('data.finalized_at'));
        $this->assertFalse($response->json('data.can.delete'));
        $this->assertTrue($response->json('data.can.reopen'));
    }

    public function test_numbers_are_unique_and_sequential_within_a_day(): void
    {
        [$a, $va] = $this->seller('AAA');
        [$b, $vb] = $this->seller('BBB');
        $this->posSale([[$va, 1]]);
        $this->posSale([[$vb, 1]]);
        $idA = $this->createDraft($a);
        $idB = $this->createDraft($b);

        $numberA = $this->postJson("/api/v1/settlements/{$idA}/finalize")->json('data.number');
        $numberB = $this->postJson("/api/v1/settlements/{$idB}/finalize")->json('data.number');

        $this->assertNotSame($numberA, $numberB);
        $this->assertStringEndsWith('0001', $numberA);
        $this->assertStringEndsWith('0002', $numberB);
    }

    public function test_a_statement_with_nothing_to_settle_cannot_be_finalized(): void
    {
        [$a] = $this->seller('AAA');
        $id = $this->createDraft($a);

        $this->postJson("/api/v1/settlements/{$id}/finalize")
            ->assertStatus(409)->assertJsonPath('code', 'nothing_to_settle');

        $this->assertSame('draft', SellerSettlement::find($id)->status);
    }

    public function test_a_statement_with_only_a_deduction_can_be_finalized_as_seller_pays_store(): void
    {
        [$a] = $this->seller('AAA');
        $id = $this->createDraft($a);
        $this->postJson("/api/v1/settlements/{$id}/adjustments", [
            'kind' => 'deduction', 'label' => 'Biaya', 'reason' => 'R', 'amount' => '2000',
        ])->assertCreated();

        $this->postJson("/api/v1/settlements/{$id}/finalize")
            ->assertOk()
            ->assertJsonPath('data.net_amount', '-2000.00')
            ->assertJsonPath('data.direction', 'seller_pays_store');
    }

    public function test_tampered_rows_make_the_reconciliation_fail_and_nothing_is_frozen(): void
    {
        [$a, $va] = $this->seller('AAA', 10000);
        $this->posSale([[$va, 1]]);
        $id = $this->createDraft($a);
        $this->postJson("/api/v1/settlements/{$id}/adjustments", [
            'kind' => 'deduction', 'label' => 'Biaya', 'reason' => 'R', 'amount' => '1000',
        ])->assertCreated();
        // Baris diubah di luar service: total tersimpan (−1000) tidak lagi sama dengan jumlah baris (−1).
        DB::table('seller_settlement_adjustments')->update(['amount' => '-1.00']);

        $this->postJson("/api/v1/settlements/{$id}/finalize")
            ->assertStatus(409)
            ->assertJsonPath('code', 'reconciliation_mismatch')
            ->assertJsonStructure(['figure']);

        $row = SellerSettlement::find($id);
        $this->assertSame('draft', $row->status);
        $this->assertNull($row->number);
        $this->assertSame(0, ActivityLog::where('action', 'finalized')->count());
    }

    public function test_the_database_rejects_a_total_that_does_not_add_up(): void
    {
        [$a, $va] = $this->seller('AAA');
        $this->posSale([[$va, 1]]);
        $id = $this->createDraft($a);

        $this->expectException(QueryException::class);
        DB::table('seller_settlements')->where('id', $id)->update(['net_amount' => '1.00']);
    }

    public function test_the_database_rejects_a_finalized_row_without_a_number(): void
    {
        [$a, $va] = $this->seller('AAA');
        $this->posSale([[$va, 1]]);
        $id = $this->createDraft($a);

        $this->expectException(QueryException::class);
        DB::table('seller_settlements')->where('id', $id)->update(['status' => 'finalized']);
    }

    public function test_a_finalized_statement_does_not_move_when_an_order_is_voided_but_flags_the_difference(): void
    {
        [$a, $va] = $this->seller('AAA', 10000);
        $keep = $this->posSale([[$va, 1]]);
        $void = $this->posSale([[$va, 2]]);
        $id = $this->createDraft($a);
        $this->postJson("/api/v1/settlements/{$id}/finalize")->assertOk();
        $this->getJson("/api/v1/settlements/{$id}")->assertJsonPath('data.source_changed', null);

        $this->voidOrder($void);

        $detail = $this->getJson("/api/v1/settlements/{$id}")->assertOk();
        $detail->assertJsonPath('data.pos_revenue', '30000.00')
            ->assertJsonPath('data.net_amount', '30000.00')
            ->assertJsonCount(2, 'data.sources')
            ->assertJsonPath('data.source_changed.pos_delta', '-20000.00')
            ->assertJsonPath('data.source_changed.preorder_delta', '0.00');
        $this->assertNotNull($keep);
    }

    public function test_a_later_preorder_payment_is_reported_as_a_preorder_delta(): void
    {
        [$a, $va] = $this->seller('AAA', 10000);
        $pre = $this->preorder([[$va, 1]], 4000);
        $id = $this->createDraft($a);
        $this->postJson("/api/v1/settlements/{$id}/finalize")->assertOk()->assertJsonPath('data.preorder_revenue', '4000.00');

        app(PreorderService::class)->recordPayment($pre, [
            'method' => 'cash', 'channel_id' => null, 'purpose' => 'down_payment', 'amount' => 2500,
        ]);

        $this->getJson("/api/v1/settlements/{$id}")
            ->assertJsonPath('data.preorder_revenue', '4000.00')
            ->assertJsonPath('data.source_changed.preorder_delta', '2500.00');
    }

    public function test_a_finalized_statement_is_not_editable_as_a_draft_would_be(): void
    {
        [$a, $va] = $this->seller('AAA', 10000);
        $this->posSale([[$va, 1]]);
        $id = $this->createDraft($a);
        $adj = $this->postJson("/api/v1/settlements/{$id}/adjustments", [
            'kind' => 'deduction', 'label' => 'Biaya', 'reason' => 'R', 'amount' => '1000',
        ])->json('data.adjustments.0.id');
        $this->postJson("/api/v1/settlements/{$id}/finalize")->assertOk();

        $this->postJson("/api/v1/settlements/{$id}/refresh")->assertStatus(409)->assertJsonPath('code', 'not_draft');
        $this->deleteJson("/api/v1/settlements/{$id}")->assertStatus(409)->assertJsonPath('code', 'not_draft');
        $this->postJson("/api/v1/settlements/{$id}/finalize")->assertStatus(409)->assertJsonPath('code', 'not_draft');
        $this->postJson("/api/v1/settlements/{$id}/adjustments", [
            'kind' => 'deduction', 'label' => 'L', 'reason' => 'R', 'amount' => '1',
        ])->assertStatus(409)->assertJsonPath('code', 'not_draft');
        $this->putJson("/api/v1/settlements/{$id}/adjustments/{$adj}", [
            'kind' => 'deduction', 'label' => 'L', 'reason' => 'R', 'amount' => '1',
        ])->assertStatus(409)->assertJsonPath('code', 'not_draft');
        $this->deleteJson("/api/v1/settlements/{$id}/adjustments/{$adj}")->assertStatus(409)->assertJsonPath('code', 'not_draft');
    }

    public function test_reopen_returns_to_draft_and_keeps_the_assigned_number(): void
    {
        [$a, $va] = $this->seller('AAA', 10000);
        $this->posSale([[$va, 1]]);
        $id = $this->createDraft($a);
        $number = $this->postJson("/api/v1/settlements/{$id}/finalize")->json('data.number');

        $this->postJson("/api/v1/settlements/{$id}/reopen")
            ->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.number', $number);

        // Draf boleh disegarkan/diubah lagi, lalu difinalkan dengan nomor yang sama.
        $this->posSale([[$va, 1]]);
        $this->postJson("/api/v1/settlements/{$id}/refresh")->assertOk()->assertJsonPath('data.pos_revenue', '20000.00');
        $this->postJson("/api/v1/settlements/{$id}/finalize")->assertOk()->assertJsonPath('data.number', $number);
    }

    public function test_a_draft_that_was_ever_finalized_cannot_be_deleted(): void
    {
        $id = $this->finalizedSettlement('AAA', 3, 10000);
        $this->postJson("/api/v1/settlements/{$id}/acknowledgement", ['method' => 'typed', 'signer_name' => 'Budi'])->assertCreated();
        $number = $this->getJson("/api/v1/settlements/{$id}")->json('data.number');
        $this->postJson("/api/v1/settlements/{$id}/reopen")->assertOk()->assertJsonPath('data.can.delete', false);

        $this->deleteJson("/api/v1/settlements/{$id}")->assertStatus(409)->assertJsonPath('code', 'was_finalized');

        $this->assertSame($number, SellerSettlement::find($id)->number, 'nomor tetap terpakai');
        $this->assertSame(1, DB::table('seller_settlement_acknowledgements')->count(), 'riwayat pengakuan tetap ada');
    }

    public function test_reopen_is_refused_for_a_draft(): void
    {
        [$a] = $this->seller('AAA');
        $id = $this->createDraft($a);

        $this->postJson("/api/v1/settlements/{$id}/reopen")->assertStatus(409)->assertJsonPath('code', 'not_finalized');
    }

    public function test_every_step_writes_an_audit_row(): void
    {
        [$a, $va] = $this->seller('AAA', 10000);
        $this->posSale([[$va, 1]]);
        $id = $this->createDraft($a);
        $this->postJson("/api/v1/settlements/{$id}/refresh")->assertOk();
        $adj = $this->postJson("/api/v1/settlements/{$id}/adjustments", [
            'kind' => 'deduction', 'label' => 'L', 'reason' => 'R', 'amount' => '100',
        ])->json('data.adjustments.0.id');
        $this->putJson("/api/v1/settlements/{$id}/adjustments/{$adj}", ['kind' => 'deduction', 'label' => 'L', 'reason' => 'R', 'amount' => '200'])->assertOk();
        $this->deleteJson("/api/v1/settlements/{$id}/adjustments/{$adj}")->assertOk();
        $this->postJson("/api/v1/settlements/{$id}/finalize")->assertOk();
        $this->postJson("/api/v1/settlements/{$id}/reopen")->assertOk();
        [$b] = $this->seller('BBB');
        $other = $this->createDraft($b);
        $this->deleteJson("/api/v1/settlements/{$other}")->assertNoContent();

        $actions = ActivityLog::where('entity_type', 'SellerSettlement')->orderBy('id')->pluck('action')->all();
        $this->assertSame(
            ['created', 'refreshed', 'adjustment_added', 'adjustment_updated', 'adjustment_removed', 'finalized', 'reopened', 'created', 'deleted'],
            $actions,
        );
        $this->assertSame($this->owner->id, ActivityLog::where('action', 'finalized')->value('user_id'));
    }

    public function test_a_failing_audit_write_rolls_the_finalization_back(): void
    {
        [$a, $va] = $this->seller('AAA', 10000);
        $this->posSale([[$va, 1]]);
        $id = $this->createDraft($a);

        $this->app->bind(ActivityLogger::class, fn () => new class extends ActivityLogger
        {
            public function log(?int $userId, string $action, string $entityType, ?int $entityId, ?string $description = null, ?array $oldValues = null, ?array $newValues = null): ActivityLog
            {
                if ($action === 'finalized') {
                    throw new \RuntimeException('audit down');
                }

                return parent::log($userId, $action, $entityType, $entityId, $description, $oldValues, $newValues);
            }
        });

        $this->postJson("/api/v1/settlements/{$id}/finalize")->assertStatus(500);

        $row = SellerSettlement::find($id);
        $this->assertSame('draft', $row->status);
        $this->assertNull($row->number);
        $this->assertNull($row->finalized_at);
    }

    public function test_a_net_zero_statement_finalizes_and_has_nothing_to_pay(): void
    {
        [$a, $va] = $this->seller('AAA', 10000);
        $this->posSale([[$va, 1]]);
        $id = $this->createDraft($a);
        $this->postJson("/api/v1/settlements/{$id}/adjustments", [
            'kind' => 'deduction', 'label' => 'Biaya', 'reason' => 'R', 'amount' => '10000',
        ])->assertCreated();

        $this->postJson("/api/v1/settlements/{$id}/finalize")
            ->assertOk()
            ->assertJsonPath('data.net_amount', '0.00')
            ->assertJsonPath('data.direction', 'none')
            ->assertJsonPath('data.payment_status', 'net_zero')
            ->assertJsonPath('data.can.add_payment', false);
    }
}
