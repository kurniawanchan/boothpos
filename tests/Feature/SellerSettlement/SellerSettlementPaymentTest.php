<?php

namespace Tests\Feature\SellerSettlement;

use App\Models\ActivityLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BuildsSettlementFixtures;
use Tests\TestCase;

/**
 * 076 (US3) — pembayaran settlement: saldo = sebelumnya − pembayaran, tidak
 * pernah lebih dari yang terutang, idempoten lewat client_ref, arah mengikuti
 * tanda net, dan cache paid_amount selalu sama dengan jumlah barisnya.
 */
class SellerSettlementPaymentTest extends TestCase
{
    use BuildsSettlementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpSettlementWorld();
    }

    private function pay(int $id, string $amount, array $overrides = [])
    {
        return $this->postJson("/api/v1/settlements/{$id}/payments", $this->paymentPayload($amount, $overrides));
    }

    public function test_a_draft_cannot_be_paid(): void
    {
        [$a, $va] = $this->seller('AAA');
        $this->posSale([[$va, 1]]);
        $id = $this->createDraft($a);

        $this->pay($id, '1000')->assertStatus(409)->assertJsonPath('code', 'not_finalized');
    }

    public function test_a_payment_reduces_the_balance_by_exactly_its_amount(): void
    {
        $id = $this->finalizedSettlement(units: 3, price: 10000); // net 30.000

        $response = $this->pay($id, '10000.50')->assertCreated();

        $response->assertJsonPath('data.paid_amount', '10000.50')
            ->assertJsonPath('data.balance', '19999.50')
            ->assertJsonPath('data.payment_status', 'partially_paid')
            ->assertJsonCount(1, 'data.payments')
            ->assertJsonPath('data.payments.0.method', 'bank_transfer')
            ->assertJsonPath('data.payments.0.reference', 'TRF-001')
            ->assertJsonPath('data.payments.0.recorded_by_name', $this->owner->name)
            ->assertJsonPath('data.reconciliation.payment_check', '0.00');
    }

    public function test_the_status_follows_the_derived_rule_after_acknowledgement(): void
    {
        $id = $this->finalizedSettlement(units: 3, price: 10000);
        $this->pay($id, '10000')->assertCreated()->assertJsonPath('data.display_status', 'finalized');
        $this->postJson("/api/v1/settlements/{$id}/acknowledgement", ['method' => 'typed', 'signer_name' => 'Budi'])
            ->assertCreated()->assertJsonPath('data.display_status', 'partially_paid');

        $this->pay($id, '20000')->assertCreated()
            ->assertJsonPath('data.display_status', 'settled')
            ->assertJsonPath('data.balance', '0.00')
            ->assertJsonPath('data.can.add_payment', false);
    }

    public function test_an_overpayment_is_refused_and_names_the_maximum(): void
    {
        $id = $this->finalizedSettlement(units: 3, price: 10000);
        $this->pay($id, '10000')->assertCreated();

        $response = $this->pay($id, '20000.01')->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertStringContainsString('20.000', $response->json('errors.amount.0'));
        $this->assertSame(1, DB::table('seller_settlement_payments')->count());
    }

    public function test_a_fully_paid_settlement_refuses_more(): void
    {
        $id = $this->finalizedSettlement(units: 1, price: 10000);
        $this->pay($id, '10000')->assertCreated();

        $this->pay($id, '1')->assertStatus(409)->assertJsonPath('code', 'already_settled');
    }

    public function test_a_net_zero_settlement_needs_no_payment(): void
    {
        $id = $this->finalizedSettlement(units: 1, price: 10000, deduction: 10000);

        $this->pay($id, '1')->assertStatus(409)->assertJsonPath('code', 'nothing_to_pay');
    }

    public function test_the_same_client_ref_replays_without_a_second_row(): void
    {
        $id = $this->finalizedSettlement(units: 3, price: 10000);
        $payload = $this->paymentPayload('5000');

        $this->postJson("/api/v1/settlements/{$id}/payments", $payload)->assertCreated();
        $replay = $this->postJson("/api/v1/settlements/{$id}/payments", $payload)->assertOk();

        $replay->assertJsonPath('data.paid_amount', '5000.00')->assertJsonCount(1, 'data.payments');
        $this->assertSame(1, DB::table('seller_settlement_payments')->count());
        $this->assertSame(1, ActivityLog::where('action', 'payment_added')->count());
    }

    public function test_a_client_ref_used_on_another_settlement_is_refused(): void
    {
        $first = $this->finalizedSettlement('AAA', 3, 10000);
        $second = $this->finalizedSettlement('BBB', 3, 10000);
        $payload = $this->paymentPayload('5000');
        $this->postJson("/api/v1/settlements/{$first}/payments", $payload)->assertCreated();

        $this->postJson("/api/v1/settlements/{$second}/payments", $payload)
            ->assertStatus(422)->assertJsonValidationErrors('client_ref');
    }

    public function test_payment_validation(): void
    {
        $id = $this->finalizedSettlement();

        $this->pay($id, '0')->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->pay($id, '-5')->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->pay($id, '10.555')->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->pay($id, 'abc')->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->pay($id, '100', ['method' => 'crypto'])->assertStatus(422)->assertJsonValidationErrors('method');
        $this->pay($id, '100', ['paid_at' => now()->addDays(3)->toDateString()])->assertStatus(422)->assertJsonValidationErrors('paid_at');
        $this->pay($id, '100', ['client_ref' => 'not-a-uuid'])->assertStatus(422)->assertJsonValidationErrors('client_ref');
        $this->pay($id, '100', ['reference' => str_repeat('x', 121)])->assertStatus(422)->assertJsonValidationErrors('reference');
        $this->assertSame(0, DB::table('seller_settlement_payments')->count());
    }

    public function test_when_the_seller_owes_the_store_the_payment_is_money_received(): void
    {
        [$a] = $this->seller('AAA');
        $id = $this->createDraft($a);
        $this->postJson("/api/v1/settlements/{$id}/adjustments", ['kind' => 'deduction', 'label' => 'Biaya', 'reason' => 'R', 'amount' => '2000'])->assertCreated();
        $this->postJson("/api/v1/settlements/{$id}/finalize")->assertOk()->assertJsonPath('data.direction', 'seller_pays_store');

        $this->pay($id, '2000.01')->assertStatus(422);
        $this->pay($id, '2000')->assertCreated()
            ->assertJsonPath('data.balance', '0.00')
            ->assertJsonPath('data.payment_status', 'settled')
            ->assertJsonPath('data.direction', 'seller_pays_store');
    }

    public function test_deleting_a_payment_recomputes_the_cache_and_is_audited(): void
    {
        $id = $this->finalizedSettlement(units: 3, price: 10000);
        $this->pay($id, '10000')->assertCreated();
        $second = $this->pay($id, '5000')->assertCreated()->json('data.payments.1.id');

        $this->deleteJson("/api/v1/settlements/{$id}/payments/{$second}")
            ->assertOk()
            ->assertJsonPath('data.paid_amount', '10000.00')
            ->assertJsonPath('data.balance', '20000.00')
            ->assertJsonCount(1, 'data.payments');
        $this->assertSame(1, ActivityLog::where('action', 'payment_removed')->count());
    }

    public function test_a_payment_of_another_settlement_cannot_be_deleted_through_this_one(): void
    {
        $first = $this->finalizedSettlement('AAA', 3, 10000);
        $second = $this->finalizedSettlement('BBB', 3, 10000);
        $paymentId = $this->pay($first, '5000')->json('data.payments.0.id');

        $this->deleteJson("/api/v1/settlements/{$second}/payments/{$paymentId}")->assertNotFound();
        $this->assertSame(1, DB::table('seller_settlement_payments')->count());
    }

    public function test_a_settlement_with_payments_cannot_be_reopened(): void
    {
        $id = $this->finalizedSettlement(units: 3, price: 10000);
        $this->pay($id, '1000')->assertCreated();

        $this->postJson("/api/v1/settlements/{$id}/reopen")->assertStatus(409)->assertJsonPath('code', 'has_payments');
    }

    public function test_the_proof_is_stored_privately_with_a_random_name(): void
    {
        $id = $this->finalizedSettlement(units: 3, price: 10000);

        $response = $this->post("/api/v1/settlements/{$id}/payments", $this->paymentPayload('5000', ['proof' => $this->pngUpload('bukti-transfer.png')]), ['Accept' => 'application/json'])
            ->assertCreated();

        $response->assertJsonPath('data.payments.0.has_proof', true);
        $path = DB::table('seller_settlement_payments')->value('proof_path');
        $this->assertMatchesRegularExpression('#^settlement-proofs/[0-9a-f-]{36}\.png$#', $path);
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_wrong_file_type_for_the_proof_is_refused(): void
    {
        $id = $this->finalizedSettlement();

        $this->post("/api/v1/settlements/{$id}/payments", $this->paymentPayload('100', [
            'proof' => \Illuminate\Http\UploadedFile::fake()->create('bukti.exe', 1, 'application/octet-stream'),
        ]), ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('proof');
        $this->assertSame(0, DB::table('seller_settlement_payments')->count());
    }

    public function test_the_database_caps_paid_at_the_amount_owed(): void
    {
        $id = $this->finalizedSettlement(units: 1, price: 10000);

        $this->expectException(QueryException::class);
        DB::table('seller_settlements')->where('id', $id)->update(['paid_amount' => '10000.01']);
    }

    public function test_the_paid_cache_always_equals_the_sum_of_the_payment_rows(): void
    {
        $id = $this->finalizedSettlement(units: 5, price: 10000);
        foreach (['1000.10', '2000.20', '3000.30'] as $amount) {
            $this->pay($id, $amount)->assertCreated();
        }
        $removeId = DB::table('seller_settlement_payments')->orderBy('id')->value('id');
        $this->deleteJson("/api/v1/settlements/{$id}/payments/{$removeId}")->assertOk();

        $sum = DB::table('seller_settlement_payments')->sum('amount');
        $this->assertSame(number_format((float) $sum, 2, '.', ''), DB::table('seller_settlements')->where('id', $id)->value('paid_amount'));
        $this->getJson("/api/v1/settlements/{$id}")->assertJsonPath('data.reconciliation.payment_check', '0.00')
            ->assertJsonPath('data.reconciliation.balanced', true);
    }

    public function test_payments_do_not_touch_the_customer_payments_ledger(): void
    {
        $id = $this->finalizedSettlement(units: 3, price: 10000);
        $before = DB::table('payments')->count();

        $this->pay($id, '5000')->assertCreated();

        $this->assertSame($before, DB::table('payments')->count());
        $this->assertTrue(Str::isUuid(DB::table('seller_settlement_payments')->value('client_ref')));
    }
}
