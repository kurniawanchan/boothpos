<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\LicenseActivation;
use App\Models\Outlet;
use App\Support\InvalidSelfServiceQr;
use App\Support\ModeGate;
use App\Support\SelfServiceQrToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 090 / Foundational — token QR meja: tidak bisa dipalsukan, bisa dicabut, mengikat mode dan outlet (research D6).
 * Memakai rute publik `GET /self-service/{token}/context` sebagai probe middleware `self-service.table`.
 */
class SelfServiceQrTokenTest extends TestCase
{
    use RefreshDatabase;

    private DiningTable $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->table = DiningTable::factory()->create(['name' => 'Meja 5', 'is_active' => true]);
    }

    private function context(string $token)
    {
        return $this->getJson('/api/v1/self-service/'.$token.'/context');
    }

    private function reason(callable $fn): ?string
    {
        try {
            $fn();
        } catch (InvalidSelfServiceQr $e) {
            return $e->reason;
        }

        return null;
    }

    public function test_a_freshly_issued_token_opens_the_right_table_without_login(): void
    {
        $token = SelfServiceQrToken::issue($this->table);

        $this->context($token)->assertOk()
            ->assertJsonPath('data.table.name', 'Meja 5')
            ->assertJsonPath('data.outlet.name', Outlet::find($this->table->outlet_id)->name)
            ->assertJsonPath('data.available', true);
    }

    public function test_changing_the_table_or_outlet_id_inside_the_token_is_refused(): void
    {
        $token = SelfServiceQrToken::issue($this->table);
        [$payload, $signature] = explode('.', $token);
        $fields = explode(':', base64_decode(strtr($payload, '-_', '+/')));

        // Id meja diganti, tanda tangan lama dipertahankan.
        $fields[0] = (string) ($this->table->id + 1);
        $forgedPayload = rtrim(strtr(base64_encode(implode(':', $fields)), '+/', '-_'), '=');
        $this->context($forgedPayload.'.'.$signature)->assertNotFound()->assertJsonPath('code', 'qr_invalid');

        // Id outlet diganti.
        $fields = explode(':', base64_decode(strtr($payload, '-_', '+/')));
        $fields[1] = (string) ($this->table->outlet_id + 1);
        $forgedPayload = rtrim(strtr(base64_encode(implode(':', $fields)), '+/', '-_'), '=');
        $this->context($forgedPayload.'.'.$signature)->assertNotFound()->assertJsonPath('code', 'qr_invalid');
    }

    public function test_garbage_and_wrong_signature_tokens_all_look_the_same_from_outside(): void
    {
        foreach (['abc', 'a.b', '.', 'x.', '.y', 'a.b.c', str_repeat('A', 200)] as $garbage) {
            $this->context($garbage)->assertNotFound()->assertJsonPath('code', 'qr_invalid');
        }

        $token = SelfServiceQrToken::issue($this->table);
        $this->context($token.'x')->assertNotFound()->assertJsonPath('code', 'qr_invalid');
    }

    public function test_rotating_the_nonce_kills_the_old_qr_and_the_new_one_works(): void
    {
        $old = SelfServiceQrToken::issue($this->table);
        $new = SelfServiceQrToken::rotate($this->table);

        $this->assertNotSame($old, $new);
        $this->context($old)->assertNotFound()->assertJsonPath('code', 'qr_invalid');
        $this->context($new)->assertOk();
    }

    public function test_an_inactive_or_deleted_table_is_unavailable_not_invalid(): void
    {
        $token = SelfServiceQrToken::issue($this->table);

        $this->table->update(['is_active' => false]);
        $this->context($token)->assertStatus(409)->assertJsonPath('code', 'table_unavailable');

        $this->table->delete();
        $this->context($token)->assertStatus(409)->assertJsonPath('code', 'table_unavailable');
    }

    public function test_an_inactive_outlet_makes_the_table_unavailable(): void
    {
        $token = SelfServiceQrToken::issue($this->table);
        Outlet::query()->whereKey($this->table->outlet_id)->update(['is_active' => false]);

        $this->context($token)->assertStatus(409)->assertJsonPath('code', 'table_unavailable');
    }

    public function test_a_token_from_the_other_data_mode_is_unavailable(): void
    {
        $demoToken = ModeGate::runAs('demo', function () {
            $outlet = Outlet::factory()->create();
            $table = DiningTable::factory()->create(['outlet_id' => $outlet->id, 'name' => 'Meja Demo']);

            return SelfServiceQrToken::issue($table);
        });

        // Sistem sedang LIVE: token yang diterbitkan saat DEMO tidak berlaku.
        $this->assertSame('live', ModeGate::current());
        $this->assertSame(InvalidSelfServiceQr::TABLE_UNAVAILABLE, $this->reason(fn () => SelfServiceQrToken::verify($demoToken)));
        $this->context($demoToken)->assertStatus(409)->assertJsonPath('code', 'table_unavailable');
    }

    public function test_the_public_route_still_requires_an_activated_installation(): void
    {
        $token = SelfServiceQrToken::issue($this->table);
        LicenseActivation::query()->delete();

        $this->context($token)->assertStatus(423);
    }

    public function test_the_response_never_leaks_internal_ids_or_the_nonce(): void
    {
        $token = SelfServiceQrToken::issue($this->table);

        $body = json_encode($this->context($token)->assertOk()->json());

        $this->assertStringNotContainsString((string) $this->table->qr_nonce, $body);
        $this->assertStringNotContainsString('qr_nonce', $body);
        $this->assertStringNotContainsString('outlet_id', $body);
    }
}
