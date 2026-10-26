<?php

namespace Tests\Feature\SellerSettlement;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsSettlementFixtures;
use Tests\TestCase;

/**
 * 076 (US3) — tanda tangan dan bukti bayar hanya keluar lewat endpoint yang
 * memeriksa hak akses, dengan header anti-cache, dari disk privat; seluruh
 * endpoint dokumen/berkas juga tertutup untuk peran tanpa menu settlements.
 */
class SellerSettlementFilesTest extends TestCase
{
    use BuildsSettlementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpSettlementWorld();
    }

    /** @return array{0:int,1:int,2:int} settlementId, ackId, paymentId */
    private function withFiles(): array
    {
        $id = $this->finalizedSettlement('AAA', 3, 10000);
        $this->post("/api/v1/settlements/{$id}/acknowledgement", [
            'method' => 'signature', 'signer_name' => 'Budi', 'signature' => $this->pngUpload('sig.png'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->post("/api/v1/settlements/{$id}/payments", $this->paymentPayload('5000', ['proof' => $this->pngUpload('bukti.png')]), ['Accept' => 'application/json'])->assertCreated();

        return [
            $id,
            (int) DB::table('seller_settlement_acknowledgements')->value('id'),
            (int) DB::table('seller_settlement_payments')->value('id'),
        ];
    }

    public function test_the_signature_streams_with_private_no_store_headers(): void
    {
        [$id, $ackId] = $this->withFiles();

        $response = $this->get("/api/v1/settlements/{$id}/acknowledgements/{$ackId}/signature")->assertOk();

        $this->assertSame($this->pngBytes(), $response->getContent());
        $this->assertStringContainsString('image/png', $response->headers->get('Content-Type'));
        $cache = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('no-store', $cache);
    }

    public function test_the_payment_proof_streams_with_private_no_store_headers(): void
    {
        [$id, , $paymentId] = $this->withFiles();

        $response = $this->get("/api/v1/settlements/{$id}/payments/{$paymentId}/proof")->assertOk();

        $this->assertStringContainsString('image/png', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_a_payment_without_a_proof_or_an_acknowledgement_without_a_signature_is_not_found(): void
    {
        $id = $this->finalizedSettlement('AAA', 3, 10000);
        $this->postJson("/api/v1/settlements/{$id}/acknowledgement", ['method' => 'typed', 'signer_name' => 'Budi'])->assertCreated();
        $this->postJson("/api/v1/settlements/{$id}/payments", $this->paymentPayload('1000'))->assertCreated();
        $ackId = DB::table('seller_settlement_acknowledgements')->value('id');
        $paymentId = DB::table('seller_settlement_payments')->value('id');

        $this->get("/api/v1/settlements/{$id}/acknowledgements/{$ackId}/signature")->assertNotFound();
        $this->get("/api/v1/settlements/{$id}/payments/{$paymentId}/proof")->assertNotFound();
    }

    public function test_files_of_another_settlement_are_not_reachable_through_this_one(): void
    {
        [$id, $ackId, $paymentId] = $this->withFiles();
        $other = $this->finalizedSettlement('BBB', 3, 10000);

        $this->get("/api/v1/settlements/{$other}/acknowledgements/{$ackId}/signature")->assertNotFound();
        $this->get("/api/v1/settlements/{$other}/payments/{$paymentId}/proof")->assertNotFound();
        $this->assertNotNull($id);
    }

    public function test_every_file_and_document_endpoint_is_closed_to_roles_without_the_menu(): void
    {
        [$id, $ackId, $paymentId] = $this->withFiles();

        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');
        $payload = $this->paymentPayload('100');

        foreach ([
            ['GET', "/api/v1/settlements/{$id}/acknowledgements/{$ackId}/signature", []],
            ['GET', "/api/v1/settlements/{$id}/payments/{$paymentId}/proof", []],
            ['GET', "/api/v1/settlements/{$id}/document", []],
            ['GET', "/api/v1/settlements/{$id}/payments/{$paymentId}/document", []],
            ['POST', "/api/v1/settlements/{$id}/acknowledgement", ['method' => 'typed', 'signer_name' => 'x']],
            ['POST', "/api/v1/settlements/{$id}/payments", $payload],
            ['DELETE', "/api/v1/settlements/{$id}/payments/{$paymentId}", []],
        ] as [$method, $uri, $body]) {
            $this->json($method, $uri, $body)->assertForbidden("kasir harus 403 di {$method} {$uri}");
        }
        $this->assertSame(1, DB::table('seller_settlement_payments')->count());
    }

    public function test_guests_are_unauthenticated_on_file_endpoints(): void
    {
        [$id, $ackId, $paymentId] = $this->withFiles();
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/v1/settlements/{$id}/acknowledgements/{$ackId}/signature")->assertUnauthorized();
        $this->getJson("/api/v1/settlements/{$id}/payments/{$paymentId}/proof")->assertUnauthorized();
        $this->getJson("/api/v1/settlements/{$id}/document")->assertUnauthorized();
    }

    public function test_a_superseded_signature_file_is_kept_for_audit(): void
    {
        $id = $this->finalizedSettlement('AAA', 3, 10000);
        $this->post("/api/v1/settlements/{$id}/acknowledgement", [
            'method' => 'signature', 'signer_name' => 'Budi', 'signature' => $this->pngUpload('a.png'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $oldPath = DB::table('seller_settlement_acknowledgements')->value('signature_path');

        $this->postJson("/api/v1/settlements/{$id}/acknowledgement", ['method' => 'typed', 'signer_name' => 'Budi'])->assertCreated();

        Storage::disk('local')->assertExists($oldPath);
    }
}
