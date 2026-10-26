<?php

namespace Tests\Feature\SellerSettlement;

use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsSettlementFixtures;
use Tests\TestCase;

/**
 * 076 (US3/FR-018/FR-019/FR-023) — bahan dokumen cetak: bukti settlement dan
 * bukti pembayaran. Identitas toko + seller + angka beku; TANPA data pribadi
 * pelanggan, dan tanpa biaya/laba kecuali diminta eksplisit.
 */
class SellerSettlementDocumentTest extends TestCase
{
    use BuildsSettlementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpSettlementWorld();
        Setting::updateOrCreate(['key' => 'store_name'], ['value' => 'Toko Uji Settlement', 'type' => 'string', 'group' => 'receipt']);
    }

    public function test_a_draft_has_no_document(): void
    {
        [$a, $va] = $this->seller('AAA');
        $this->posSale([[$va, 1]]);
        $id = $this->createDraft($a);

        $this->getJson("/api/v1/settlements/{$id}/document")->assertStatus(409)->assertJsonPath('code', 'not_finalized');
    }

    public function test_the_document_carries_identity_figures_reconciliation_and_acknowledgement(): void
    {
        $id = $this->finalizedSettlement('AAA', 4, 10000, deduction: 5000); // net 35.000
        $this->postJson("/api/v1/settlements/{$id}/acknowledgement", ['method' => 'typed', 'signer_name' => 'Budi Seller'])->assertCreated();
        $number = $this->getJson("/api/v1/settlements/{$id}")->json('data.number');

        $doc = $this->getJson("/api/v1/settlements/{$id}/document")->assertOk()->json('data');

        $this->assertSame('Toko Uji Settlement', $doc['store_identity']['name']);
        $this->assertSame('BoothPOS', $doc['app_name']);
        $this->assertSame($number, $doc['settlement']['number']);
        $this->assertSame('Seller AAA', $doc['settlement']['seller']['name']);
        $this->assertSame($this->event->name, $doc['settlement']['event']['name']);
        $this->assertSame('store_pays_seller', $doc['settlement']['direction']);
        $this->assertSame('40000.00', $doc['lines']['revenue_total']);
        $this->assertSame('-5000.00', $doc['lines']['adjustments_total']);
        $this->assertSame('35000.00', $doc['lines']['net_amount']);
        $this->assertSame('35000.00', $doc['lines']['balance']);
        $this->assertCount(1, $doc['lines']['adjustments']);
        $this->assertTrue($doc['reconciliation']['balanced']);
        $this->assertSame('Budi Seller', $doc['acknowledgement']['signer_name']);
        $this->assertSame('typed', $doc['acknowledgement']['method']);
        $this->assertNotEmpty($doc['sources']);
    }

    public function test_no_customer_personal_data_ever_appears_in_the_document(): void
    {
        [$a, $va] = $this->seller('AAA', 10000);
        $customer = Customer::factory()->create(['name' => 'Pelanggan Rahasia Xyz', 'phone' => '+62-811-5550-0199', 'email' => 'rahasia.xyz@example.test']);
        app(\App\Services\PreorderService::class)->recordPayment(
            app(\App\Services\PreorderService::class)->create([
                'event_id' => $this->event->id, 'customer_id' => $customer->id, 'fulfillment' => 'pickup',
                'items' => [['variant_id' => $va->id, 'qty' => 2]],
            ], $this->owner),
            ['method' => 'cash', 'channel_id' => null, 'purpose' => 'down_payment', 'amount' => 20000],
        );
        $id = $this->createDraft($a);
        $this->postJson("/api/v1/settlements/{$id}/finalize")->assertOk();

        $json = $this->getJson("/api/v1/settlements/{$id}/document")->assertOk()->getContent();

        foreach (['Pelanggan Rahasia Xyz', '+62-811-5550-0199', 'rahasia.xyz@example.test', 'customer'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $json, "dokumen tidak boleh memuat {$needle}");
        }
    }

    public function test_cost_and_profit_are_omitted_unless_explicitly_requested(): void
    {
        $id = $this->finalizedSettlement('AAA', 3, 10000);

        $plain = $this->getJson("/api/v1/settlements/{$id}/document")->assertOk()->json('data');
        $this->assertArrayNotHasKey('cost_of_goods', $plain['memo'] ?? []);
        $this->assertArrayNotHasKey('gross_profit', $plain['memo'] ?? []);
        $this->assertStringNotContainsString('cost_amount', json_encode($plain));

        $withCost = $this->getJson("/api/v1/settlements/{$id}/document?include_cost=1")->assertOk()->json('data');
        $this->assertSame('12000.00', $withCost['memo']['cost_of_goods']);
        $this->assertSame('18000.00', $withCost['memo']['gross_profit']);
    }

    public function test_the_document_lists_payments_and_the_payment_proof_shows_the_balance_after_each(): void
    {
        $id = $this->finalizedSettlement('AAA', 3, 10000); // net 30.000
        $first = $this->postJson("/api/v1/settlements/{$id}/payments", $this->paymentPayload('10000', ['paid_at' => now()->subDay()->toDateString(), 'reference' => 'REF-A']))->assertCreated()->json('data.payments.0.id');
        $second = $this->postJson("/api/v1/settlements/{$id}/payments", $this->paymentPayload('5000', ['reference' => 'REF-B']))->assertCreated()->json('data.payments.1.id');

        $settlementDoc = $this->getJson("/api/v1/settlements/{$id}/document")->json('data');
        $this->assertCount(2, $settlementDoc['payments']);
        $this->assertSame('15000.00', $settlementDoc['lines']['paid_amount']);

        $docFirst = $this->getJson("/api/v1/settlements/{$id}/payments/{$first}/document")->assertOk()->json('data');
        $docSecond = $this->getJson("/api/v1/settlements/{$id}/payments/{$second}/document")->assertOk()->json('data');

        $this->assertSame('10000.00', $docFirst['payment']['amount']);
        $this->assertSame('20000.00', $docFirst['balance_after']);
        $this->assertSame('REF-A', $docFirst['payment']['reference']);
        $this->assertSame('15000.00', $docSecond['total_paid_after']);
        $this->assertSame('15000.00', $docSecond['balance_after']);
        $this->assertSame($this->owner->name, $docSecond['payment']['recorded_by_name']);
        $this->assertSame('Toko Uji Settlement', $docSecond['store_identity']['name']);
        $this->assertNotEmpty($docSecond['settlement']['number']);
    }

    public function test_the_payment_document_of_another_settlement_is_not_found(): void
    {
        $first = $this->finalizedSettlement('AAA', 3, 10000);
        $second = $this->finalizedSettlement('BBB', 3, 10000);
        $paymentId = $this->postJson("/api/v1/settlements/{$first}/payments", $this->paymentPayload('1000'))->json('data.payments.0.id');

        $this->getJson("/api/v1/settlements/{$second}/payments/{$paymentId}/document")->assertNotFound();
    }

    public function test_without_a_store_logo_setting_the_identity_has_no_logo_url_key_value(): void
    {
        $id = $this->finalizedSettlement();

        $doc = $this->getJson("/api/v1/settlements/{$id}/document")->json('data');

        $this->assertNull($doc['store_identity']['logo_url']);
    }
}
