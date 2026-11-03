<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SelfServiceOrder;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SelfServiceFixtures;
use Tests\TestCase;

/**
 * 090 / US6 — verifikasi dan penolakan: hanya pemegang izin QRIS, transisi sah saja, tanpa efek stok/tiket/pembayaran.
 */
class SelfServiceVerifyRejectTest extends TestCase
{
    use RefreshDatabase;
    use SelfServiceFixtures;

    private SelfServiceOrder $order;

    private $item;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ssSetUp();
        $this->item = $this->makeMenuItem(stock: 10, price: 15000);
        $this->order = $this->ssPlaceOrder([['variant_id' => $this->item->id, 'qty' => 2]]);
        $this->owner = $this->ssOwner();
        $this->actingAs($this->owner, 'sanctum');
    }

    private function verify(string $reference = 'BCA-1234', ?SelfServiceOrder $order = null)
    {
        return $this->postJson('/api/v1/self-service/orders/'.($order ?? $this->order)->id.'/verify', ['reference' => $reference]);
    }

    private function reject(string $reason = 'Bukti tidak cocok', ?SelfServiceOrder $order = null)
    {
        return $this->postJson('/api/v1/self-service/orders/'.($order ?? $this->order)->id.'/reject', ['reason' => $reason]);
    }

    private function assertNothingDownstream(): void
    {
        $this->assertSame(0, Order::count());
        $this->assertSame(0, KitchenTicket::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, StockMovement::where('type', 'sale')->count());
        $this->assertSame(10, (int) $this->item->fresh()->current_stock);
    }

    public function test_verifying_records_who_and_the_reference_but_creates_nothing_downstream(): void
    {
        $owner = $this->owner;

        $this->verify('BCA-1234')->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.payment.status', 'verified')
            ->assertJsonPath('data.payment.reference', 'BCA-1234')
            ->assertJsonPath('data.payment.verified_by', $owner->name);

        $row = $this->order->fresh();
        $this->assertSame($owner->id, (int) $row->verified_by);
        $this->assertNotNull($row->verified_at);
        $this->assertNothingDownstream();

        $log = ActivityLog::where('action', 'self_service_verified')->firstOrFail();
        $this->assertSame($owner->id, $log->user_id);
        $this->assertSame($this->order->id, (int) $log->entity_id);
    }

    public function test_the_reference_must_be_at_least_four_characters(): void
    {
        $this->verify('abc')->assertStatus(422)->assertJsonValidationErrors('reference');
        $this->postJson("/api/v1/self-service/orders/{$this->order->id}/verify", [])->assertStatus(422);
        $this->assertSame(SelfServiceOrder::AWAITING, $this->order->fresh()->status);
    }

    public function test_a_cashier_without_the_qris_permission_cannot_verify_or_reject(): void
    {
        $this->actingAs($this->ssCashier(), 'sanctum');

        $this->verify()->assertForbidden()->assertJsonPath('code', 'verify_not_allowed');
        $this->reject()->assertForbidden()->assertJsonPath('code', 'verify_not_allowed');
        $this->assertSame(SelfServiceOrder::AWAITING, $this->order->fresh()->status);
    }

    public function test_a_role_without_the_menu_is_refused_before_anything_else(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'inventory']), 'sanctum');

        $this->verify()->assertForbidden();
        $this->reject()->assertForbidden();
    }

    public function test_a_second_verify_and_a_verify_after_reject_are_conflicts(): void
    {
        $this->verify()->assertOk();
        $this->verify('BCA-9999')->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
        $this->assertSame('BCA-1234', $this->order->fresh()->verify_reference, 'referensi pertama tidak tertimpa');

        $other = $this->ssPlaceOrder([['variant_id' => $this->item->id, 'qty' => 1]]);
        $this->reject('x', $other)->assertOk();
        $this->verify('BCA-1234', $other)->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
    }

    public function test_rejecting_needs_a_reason_is_final_and_creates_nothing_downstream(): void
    {
        $this->reject('')->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->reject('Bukti tidak cocok dengan mutasi')->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection.reason', 'Bukti tidak cocok dengan mutasi')
            ->assertJsonPath('data.payment.status', 'rejected');

        $this->assertNothingDownstream();
        $this->assertSame('rejected', $this->order->fresh()->status);
        $this->assertNotNull(ActivityLog::where('action', 'self_service_rejected')->first());

        // Final: tidak bisa ditolak lagi, diverifikasi, maupun dikirim ke dapur.
        $this->reject()->assertStatus(409);
        $this->verify()->assertStatus(409);
        $this->postJson("/api/v1/self-service/orders/{$this->order->id}/dispatch")->assertStatus(409);
    }

    public function test_a_verified_order_can_still_be_rejected(): void
    {
        $this->verify()->assertOk();

        $this->reject('Ternyata dana tidak masuk')->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertNothingDownstream();
    }

    public function test_the_activity_log_never_carries_personal_data_or_the_proof(): void
    {
        $this->verify('BCA-1234')->assertOk();
        $this->reject('x', $this->ssPlaceOrder([['variant_id' => $this->item->id, 'qty' => 1]]))->assertOk();

        $dump = json_encode(ActivityLog::whereIn('action', ['self_service_submitted', 'self_service_verified', 'self_service_rejected'])->get()->toArray());
        foreach (['customer_', 'phone', 'email', 'proof_token', 'receipt_token'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dump);
        }
    }
}
