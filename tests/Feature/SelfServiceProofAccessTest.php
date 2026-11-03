<?php

namespace Tests\Feature;

use App\Models\SelfServiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OutletFixtures;
use Tests\Support\SelfServiceFixtures;
use Tests\TestCase;

/**
 * 090 / US6 — foto bukti dari pelanggan publik hanya bisa dibuka staf berwenang di outlet yang terlihat (tanpa URL publik).
 */
class SelfServiceProofAccessTest extends TestCase
{
    use OutletFixtures;
    use RefreshDatabase;
    use SelfServiceFixtures;

    private SelfServiceOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->ssSetUp();
        $item = $this->makeMenuItem();
        $this->order = $this->ssPlaceOrder([['variant_id' => $item->id, 'qty' => 1]]);
        Storage::disk('local')->put($this->order->proof->file_path, 'JPEGDATA');
    }

    private function open(?User $user = null)
    {
        if ($user !== null) {
            $this->actingAs($user, 'sanctum');
        }

        return $this->get('/api/v1/payment-proofs/'.$this->order->proof_id.'/file', ['Accept' => 'application/json']);
    }

    public function test_staff_with_the_self_service_menu_can_open_the_proof(): void
    {
        $res = $this->open($this->ssCashier())->assertOk();

        $this->assertSame('JPEGDATA', $res->getContent());
        $this->assertStringContainsString('image/jpeg', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('private', $res->headers->get('Cache-Control'));
    }

    public function test_the_owner_can_open_it_and_a_role_without_the_menu_cannot(): void
    {
        $this->open($this->ssOwner())->assertOk();
        $this->open(User::factory()->create(['role' => 'inventory']))->assertForbidden();
    }

    public function test_guests_cannot_open_it(): void
    {
        $this->app['auth']->forgetGuards();

        $this->open()->assertUnauthorized();
    }

    public function test_staff_of_another_outlet_cannot_open_it(): void
    {
        [$mine, $other] = $this->makeOutlets(2);
        $foreignOrder = $this->ssPlaceOrder([['variant_id' => $this->makeMenuItem()->id, 'qty' => 1]], [], \App\Support\SelfServiceQrToken::issue($this->tableAt($other, 'B1')));
        Storage::disk('local')->put($foreignOrder->proof->file_path, 'SECRET');
        $user = $this->assignUser($this->ssCashier(), [$mine]);
        $this->actingAs($user, 'sanctum');

        $this->get('/api/v1/payment-proofs/'.$foreignOrder->proof_id.'/file', ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_a_proof_that_is_not_from_a_self_service_order_keeps_the_old_rule(): void
    {
        $token = $this->ssProofToken();
        $stray = \App\Models\PaymentProof::where('proof_token', $token)->firstOrFail();
        Storage::disk('local')->put($stray->file_path, 'X');

        $this->actingAs($this->ssCashier(), 'sanctum');
        $this->get('/api/v1/payment-proofs/'.$stray->id.'/file', ['Accept' => 'application/json'])->assertForbidden();
    }
}
