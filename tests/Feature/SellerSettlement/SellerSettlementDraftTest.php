<?php

namespace Tests\Feature\SellerSettlement;

use App\Models\Event;
use App\Models\SellerSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSettlementFixtures;
use Tests\TestCase;

/**
 * 076 (US2) — membuat draft, preview, refresh, hapus, dan penyesuaian/potongan
 * pada draft. Keseimbangan angka: net = pendapatan + penyesuaian, dan tidak
 * ada endpoint yang menerima angka pendapatan dari klien.
 */
class SellerSettlementDraftTest extends TestCase
{
    use BuildsSettlementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSettlementWorld();
    }

    public function test_creating_a_draft_snapshots_the_sources_and_has_no_number_yet(): void
    {
        [$a, $va] = $this->seller('AAA', 10000);
        $order = $this->posSale([[$va, 3]]);
        $this->preorder([[$va, 2]], 5000);

        $response = $this->postJson('/api/v1/settlements', ['event_id' => $this->event->id, 'artist_id' => $a->id])
            ->assertCreated();

        $response->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.display_status', 'draft')
            ->assertJsonPath('data.number', null)
            ->assertJsonPath('data.pos_revenue', '30000.00')
            ->assertJsonPath('data.preorder_revenue', '5000.00')
            ->assertJsonPath('data.revenue_total', '35000.00')
            ->assertJsonPath('data.adjustments_total', '0.00')
            ->assertJsonPath('data.net_amount', '35000.00')
            ->assertJsonPath('data.direction', 'store_pays_seller')
            ->assertJsonPath('data.balance', '35000.00')
            ->assertJsonPath('data.seller.id', $a->id)
            ->assertJsonPath('data.event.id', $this->event->id)
            ->assertJsonPath('data.reconciliation.balanced', true)
            ->assertJsonPath('data.reconciliation.difference', '0.00');

        $sources = collect($response->json('data.sources'));
        $this->assertCount(2, $sources);
        $this->assertSame($order->order_number, $sources->firstWhere('source_type', 'order')['source_number']);
        $this->assertSame('15000.00', $response->json('data.memo.preorder_uncollected'));
    }

    public function test_a_second_active_settlement_for_the_same_pair_is_refused_with_the_existing_id(): void
    {
        [$a] = $this->seller('AAA');
        $id = $this->createDraft($a);

        $this->postJson('/api/v1/settlements', ['event_id' => $this->event->id, 'artist_id' => $a->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'settlement_exists')
            ->assertJsonPath('existing_id', $id);

        $this->assertSame(1, SellerSettlement::count());
    }

    public function test_the_database_itself_refuses_two_active_rows_for_one_pair(): void
    {
        [$a] = $this->seller('AAA');
        $this->createDraft($a);
        $row = (array) DB::table('seller_settlements')->first();
        unset($row['id']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('seller_settlements')->insert($row);
    }

    public function test_preview_of_a_seller_without_activity_returns_zeros_not_an_error(): void
    {
        [$a] = $this->seller('AAA');

        $this->getJson("/api/v1/settlements/preview?event_id={$this->event->id}&artist_id={$a->id}")
            ->assertOk()
            ->assertJsonPath('data.revenue_total', '0.00')
            ->assertJsonPath('data.sources', [])
            ->assertJsonPath('data.net_amount', '0.00');

        $this->assertSame(0, SellerSettlement::count(), 'preview tidak boleh menyimpan apa pun');
    }

    public function test_unknown_event_or_seller_is_a_validation_error(): void
    {
        [$a] = $this->seller('AAA');

        $this->postJson('/api/v1/settlements', ['event_id' => 99999, 'artist_id' => $a->id])->assertStatus(422);
        $this->postJson('/api/v1/settlements', ['event_id' => $this->event->id, 'artist_id' => 99999])->assertStatus(422);
        $this->postJson('/api/v1/settlements', [])->assertStatus(422);
    }

    public function test_a_revenue_amount_sent_by_the_client_is_never_used(): void
    {
        [$a, $va] = $this->seller('AAA', 10000);
        $this->posSale([[$va, 1]]);

        $this->postJson('/api/v1/settlements', [
            'event_id' => $this->event->id, 'artist_id' => $a->id,
            'pos_revenue' => '999999.00', 'revenue_total' => '999999.00', 'net_amount' => '999999.00',
        ])->assertCreated()->assertJsonPath('data.net_amount', '10000.00');
    }

    public function test_refresh_picks_up_new_sales_while_the_settlement_is_a_draft(): void
    {
        [$a, $va] = $this->seller('AAA', 10000);
        $this->posSale([[$va, 1]]);
        $id = $this->createDraft($a);
        $this->posSale([[$va, 2]]);

        $this->postJson("/api/v1/settlements/{$id}/refresh")
            ->assertOk()
            ->assertJsonPath('data.pos_revenue', '30000.00')
            ->assertJsonPath('data.net_amount', '30000.00')
            ->assertJsonCount(2, 'data.sources');
    }

    public function test_a_draft_can_be_deleted_and_the_pair_can_be_settled_again(): void
    {
        [$a] = $this->seller('AAA');
        $id = $this->createDraft($a);

        $this->deleteJson("/api/v1/settlements/{$id}")->assertNoContent();

        $this->assertSame(0, SellerSettlement::count());
        $this->createDraft($a);
    }

    public function test_a_deduction_is_entered_positive_stored_negative_and_reduces_the_net(): void
    {
        [$a, $va] = $this->seller('AAA', 10000);
        $this->posSale([[$va, 10]]);
        $id = $this->createDraft($a);

        $response = $this->postJson("/api/v1/settlements/{$id}/adjustments", [
            'kind' => 'deduction', 'label' => 'Biaya booth', 'reason' => 'Bagian biaya booth event',
            'basis' => '40% menurut porsi penjualan', 'amount' => '25000',
        ])->assertCreated();

        $response->assertJsonPath('data.adjustments_total', '-25000.00')
            ->assertJsonPath('data.net_amount', '75000.00')
            ->assertJsonPath('data.adjustments.0.amount', '-25000.00')
            ->assertJsonPath('data.adjustments.0.basis', '40% menurut porsi penjualan')
            ->assertJsonPath('data.reconciliation.balanced', true);

        $this->assertSame('-25000.00', DB::table('seller_settlement_adjustments')->value('amount'));
    }

    public function test_an_adjustment_may_be_positive_or_negative_and_never_zero(): void
    {
        [$a, $va] = $this->seller('AAA', 10000);
        $this->posSale([[$va, 1]]);
        $id = $this->createDraft($a);

        $this->postJson("/api/v1/settlements/{$id}/adjustments", ['kind' => 'adjustment', 'label' => 'Koreksi', 'reason' => 'x', 'amount' => '500'])
            ->assertCreated()->assertJsonPath('data.net_amount', '10500.00');
        $this->postJson("/api/v1/settlements/{$id}/adjustments", ['kind' => 'adjustment', 'label' => 'Koreksi', 'reason' => 'x', 'amount' => '-200'])
            ->assertCreated()->assertJsonPath('data.net_amount', '10300.00');
        $this->postJson("/api/v1/settlements/{$id}/adjustments", ['kind' => 'adjustment', 'label' => 'Koreksi', 'reason' => 'x', 'amount' => '0'])
            ->assertStatus(422);
    }

    public function test_adjustment_validation(): void
    {
        [$a] = $this->seller('AAA');
        $id = $this->createDraft($a);
        $ok = ['kind' => 'deduction', 'label' => 'L', 'reason' => 'R', 'amount' => '100'];

        $this->postJson("/api/v1/settlements/{$id}/adjustments", array_merge($ok, ['reason' => '']))->assertStatus(422);
        $this->postJson("/api/v1/settlements/{$id}/adjustments", array_merge($ok, ['label' => '']))->assertStatus(422);
        $this->postJson("/api/v1/settlements/{$id}/adjustments", array_merge($ok, ['kind' => 'commission']))->assertStatus(422);
        $this->postJson("/api/v1/settlements/{$id}/adjustments", array_merge($ok, ['amount' => 'abc']))->assertStatus(422);
        $this->postJson("/api/v1/settlements/{$id}/adjustments", array_merge($ok, ['amount' => '-100']))->assertStatus(422); // potongan diketik positif
        $this->postJson("/api/v1/settlements/{$id}/adjustments", array_merge($ok, ['amount' => '10.555']))->assertStatus(422);
        $this->postJson("/api/v1/settlements/{$id}/adjustments", array_merge($ok, ['basis' => str_repeat('x', 161)]))->assertStatus(422);
    }

    public function test_adjustments_can_be_edited_and_removed_on_a_draft_and_totals_follow(): void
    {
        [$a, $va] = $this->seller('AAA', 10000);
        $this->posSale([[$va, 10]]);
        $id = $this->createDraft($a);
        $created = $this->postJson("/api/v1/settlements/{$id}/adjustments", [
            'kind' => 'deduction', 'label' => 'Biaya', 'reason' => 'R', 'amount' => '10000',
        ])->assertCreated();
        $adjId = $created->json('data.adjustments.0.id');

        $this->putJson("/api/v1/settlements/{$id}/adjustments/{$adjId}", [
            'kind' => 'deduction', 'label' => 'Biaya', 'reason' => 'R', 'amount' => '30000',
        ])->assertOk()->assertJsonPath('data.net_amount', '70000.00');

        $this->deleteJson("/api/v1/settlements/{$id}/adjustments/{$adjId}")
            ->assertOk()->assertJsonPath('data.net_amount', '100000.00')->assertJsonPath('data.adjustments', []);
    }

    public function test_deductions_larger_than_revenue_flip_the_direction(): void
    {
        [$a, $va] = $this->seller('AAA', 10000);
        $this->posSale([[$va, 1]]);
        $id = $this->createDraft($a);

        $this->postJson("/api/v1/settlements/{$id}/adjustments", [
            'kind' => 'deduction', 'label' => 'Biaya', 'reason' => 'R', 'amount' => '15000',
        ])->assertCreated()
            ->assertJsonPath('data.net_amount', '-5000.00')
            ->assertJsonPath('data.direction', 'seller_pays_store')
            ->assertJsonPath('data.balance', '5000.00');
    }

    public function test_an_adjustment_that_belongs_to_another_settlement_is_not_found(): void
    {
        [$a] = $this->seller('AAA');
        [$b] = $this->seller('BBB');
        $idA = $this->createDraft($a);
        $idB = $this->createDraft($b);
        $adj = $this->postJson("/api/v1/settlements/{$idA}/adjustments", [
            'kind' => 'deduction', 'label' => 'L', 'reason' => 'R', 'amount' => '1',
        ])->json('data.adjustments.0.id');

        $this->deleteJson("/api/v1/settlements/{$idB}/adjustments/{$adj}")->assertNotFound();
    }

    public function test_list_filters_and_totals_follow_the_filtered_rows(): void
    {
        [$a, $va] = $this->seller('AAA', 10000);
        [$b, $vb] = $this->seller('BBB', 5000);
        $this->posSale([[$va, 2]]);
        $this->posSale([[$vb, 2]]);
        $other = Event::factory()->create(['status' => 'active']);
        $this->createDraft($a);
        $this->createDraft($b);
        $this->postJson('/api/v1/settlements', ['event_id' => $other->id, 'artist_id' => $a->id])->assertCreated();

        $all = $this->getJson('/api/v1/settlements')->assertOk();
        $all->assertJsonPath('meta.total', 3)->assertJsonPath('totals.revenue_total', '30000.00');

        $this->getJson("/api/v1/settlements?artist_id={$a->id}")->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('totals.net_amount', '20000.00');
        $this->getJson("/api/v1/settlements?event_id={$this->event->id}&artist_id={$b->id}")->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('totals.revenue_total', '10000.00');
        $this->getJson('/api/v1/settlements?status=settled')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_the_detail_payload_has_every_documented_field(): void
    {
        [$a, $va] = $this->seller('AAA', 10000);
        $this->posSale([[$va, 1]]);
        $id = $this->createDraft($a);

        $data = $this->getJson("/api/v1/settlements/{$id}")->assertOk()->json('data');

        // Perangkap relationLoaded: field tidak boleh hilang diam-diam.
        foreach ([
            'id', 'number', 'status', 'display_status', 'event', 'seller', 'pos_units', 'pos_revenue', 'preorder_units',
            'preorder_revenue', 'revenue_total', 'adjustments_total', 'net_amount', 'direction', 'paid_amount', 'balance',
            'payment_status', 'acknowledged', 'finalized_at', 'created_at', 'sources', 'adjustments', 'acknowledgement',
            'acknowledgement_history', 'payments', 'reconciliation', 'memo', 'source_changed', 'can',
        ] as $key) {
            $this->assertArrayHasKey($key, $data, "field {$key} hilang dari detail");
        }
        $this->assertTrue($data['can']['finalize']);
        $this->assertTrue($data['can']['delete']);
        $this->assertFalse($data['can']['reopen']);
        $this->assertFalse($data['can']['add_payment']);
    }
}
