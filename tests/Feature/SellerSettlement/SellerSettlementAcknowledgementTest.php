<?php

namespace Tests\Feature\SellerSettlement;

use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsSettlementFixtures;
use Tests\TestCase;

/**
 * 076 (US3) — pengakuan seller: tanda tangan gambar / nama ketik / dicatat di
 * luar sistem. Append-only: yang baru menggantikan yang lama.
 */
class SellerSettlementAcknowledgementTest extends TestCase
{
    use BuildsSettlementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpSettlementWorld();
    }

    public function test_only_a_finalized_settlement_can_be_acknowledged(): void
    {
        [$a, $va] = $this->seller('AAA');
        $this->posSale([[$va, 1]]);
        $id = $this->createDraft($a);

        $this->postJson("/api/v1/settlements/{$id}/acknowledgement", ['method' => 'typed', 'signer_name' => 'Budi'])
            ->assertStatus(409)->assertJsonPath('code', 'not_finalized');
    }

    public function test_a_typed_acknowledgement_records_name_time_and_recorder(): void
    {
        $id = $this->finalizedSettlement();

        $response = $this->postJson("/api/v1/settlements/{$id}/acknowledgement", ['method' => 'typed', 'signer_name' => 'Budi Seller'])
            ->assertCreated();

        $response->assertJsonPath('data.acknowledged', true)
            ->assertJsonPath('data.display_status', 'acknowledged')
            ->assertJsonPath('data.acknowledgement.method', 'typed')
            ->assertJsonPath('data.acknowledgement.signer_name', 'Budi Seller')
            ->assertJsonPath('data.acknowledgement.has_signature', false)
            ->assertJsonPath('data.acknowledgement.recorded_by_name', $this->owner->name);
        $this->assertNotNull($response->json('data.acknowledgement.acknowledged_at'));
        $this->assertSame(1, ActivityLog::where('action', 'acknowledged')->count());
    }

    public function test_a_drawn_signature_is_stored_on_the_private_disk_and_never_public(): void
    {
        $id = $this->finalizedSettlement();

        $response = $this->post("/api/v1/settlements/{$id}/acknowledgement", [
            'method' => 'signature', 'signer_name' => 'Budi', 'signature' => $this->pngUpload('sig.png'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $response->assertJsonPath('data.acknowledgement.has_signature', true);
        $path = DB::table('seller_settlement_acknowledgements')->value('signature_path');
        $this->assertStringStartsWith('settlement-signatures/', $path);
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame($this->pngBytes(), Storage::disk('local')->get($path));
    }

    public function test_the_signature_stored_name_is_random_not_the_uploaded_name(): void
    {
        $id = $this->finalizedSettlement();
        $this->post("/api/v1/settlements/{$id}/acknowledgement", [
            'method' => 'signature', 'signer_name' => 'Budi', 'signature' => $this->pngUpload('../../evil.png'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $path = DB::table('seller_settlement_acknowledgements')->value('signature_path');
        $this->assertStringNotContainsString('evil', $path);
        $this->assertMatchesRegularExpression('#^settlement-signatures/[0-9a-f-]{36}\.png$#', $path);
    }

    public function test_an_acknowledgement_recorded_outside_the_system_needs_a_note_and_is_labelled(): void
    {
        $id = $this->finalizedSettlement();

        $this->postJson("/api/v1/settlements/{$id}/acknowledgement", ['method' => 'external'])->assertStatus(422)->assertJsonValidationErrors('note');

        $this->postJson("/api/v1/settlements/{$id}/acknowledgement", ['method' => 'external', 'note' => 'Setuju lewat WhatsApp 26 Okt'])
            ->assertCreated()
            ->assertJsonPath('data.acknowledgement.method', 'external')
            ->assertJsonPath('data.acknowledgement.note', 'Setuju lewat WhatsApp 26 Okt');
    }

    public function test_per_method_required_fields_and_file_rules(): void
    {
        $id = $this->finalizedSettlement();
        $url = "/api/v1/settlements/{$id}/acknowledgement";
        $json = ['Accept' => 'application/json'];

        $this->postJson($url, ['method' => 'typed'])->assertStatus(422)->assertJsonValidationErrors('signer_name');
        $this->postJson($url, ['method' => 'nonsense', 'signer_name' => 'x'])->assertStatus(422)->assertJsonValidationErrors('method');
        $this->post($url, ['method' => 'signature', 'signer_name' => 'Budi'], $json)->assertStatus(422)->assertJsonValidationErrors('signature');
        $this->post($url, ['method' => 'signature', 'signature' => $this->pngUpload()], $json)->assertStatus(422)->assertJsonValidationErrors('signer_name');
        $this->post($url, ['method' => 'signature', 'signer_name' => 'Budi', 'signature' => UploadedFile::fake()->create('sig.txt', 1, 'text/plain')], $json)
            ->assertStatus(422)->assertJsonValidationErrors('signature');
        $this->post($url, ['method' => 'signature', 'signer_name' => 'Budi', 'signature' => UploadedFile::fake()->createWithContent('big.png', str_repeat('a', 600 * 1024))], $json)
            ->assertStatus(422)->assertJsonValidationErrors('signature');
        $this->assertSame(0, DB::table('seller_settlement_acknowledgements')->count());
    }

    public function test_a_new_acknowledgement_supersedes_the_previous_one_and_keeps_it_as_history(): void
    {
        $id = $this->finalizedSettlement();
        $this->postJson("/api/v1/settlements/{$id}/acknowledgement", ['method' => 'typed', 'signer_name' => 'Pertama'])->assertCreated();

        $response = $this->postJson("/api/v1/settlements/{$id}/acknowledgement", ['method' => 'typed', 'signer_name' => 'Kedua'])->assertCreated();

        $response->assertJsonPath('data.acknowledgement.signer_name', 'Kedua')
            ->assertJsonCount(1, 'data.acknowledgement_history')
            ->assertJsonPath('data.acknowledgement_history.0.signer_name', 'Pertama')
            ->assertJsonPath('data.acknowledgement_history.0.superseded_reason', 'replaced');
        $this->assertSame(1, DB::table('seller_settlement_acknowledgements')->whereNull('superseded_at')->count());
        $this->assertSame(2, DB::table('seller_settlement_acknowledgements')->count(), 'tidak ada yang dihapus');
    }

    public function test_reopening_supersedes_the_acknowledgement_with_reason_reopened(): void
    {
        $id = $this->finalizedSettlement();
        $this->postJson("/api/v1/settlements/{$id}/acknowledgement", ['method' => 'typed', 'signer_name' => 'Budi'])->assertCreated();

        $this->postJson("/api/v1/settlements/{$id}/reopen")
            ->assertOk()
            ->assertJsonPath('data.acknowledged', false)
            ->assertJsonPath('data.acknowledgement', null)
            ->assertJsonPath('data.acknowledgement_history.0.superseded_reason', 'reopened');
    }

    public function test_acknowledging_does_not_change_any_figure(): void
    {
        $id = $this->finalizedSettlement(units: 3, price: 10000, deduction: 5000);
        $before = $this->getJson("/api/v1/settlements/{$id}")->json('data');

        $this->postJson("/api/v1/settlements/{$id}/acknowledgement", ['method' => 'typed', 'signer_name' => 'Budi'])->assertCreated();

        $after = $this->getJson("/api/v1/settlements/{$id}")->json('data');
        foreach (['revenue_total', 'adjustments_total', 'net_amount', 'paid_amount', 'balance', 'number'] as $key) {
            $this->assertSame($before[$key], $after[$key], $key);
        }
    }
}
