<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_allowed_transitions_succeed_in_sequence(): void
    {
        $owner = $this->actingAsRole('owner');
        $po = PurchaseOrder::factory()->create(['created_by' => $owner->id, 'status' => 'draft']);

        $this->patchJson("/api/v1/purchase-orders/{$po->id}/status", ['status' => 'ordered'])
            ->assertOk()->assertJsonPath('status', 'ordered');

        $this->patchJson("/api/v1/purchase-orders/{$po->id}/status", ['status' => 'received'])
            ->assertOk()->assertJsonPath('status', 'received');

        $this->patchJson("/api/v1/purchase-orders/{$po->id}/status", ['status' => 'paid'])
            ->assertOk()->assertJsonPath('status', 'paid');
    }

    public function test_cancel_is_allowed_from_draft_and_ordered(): void
    {
        $owner = $this->actingAsRole('owner');
        $draft = PurchaseOrder::factory()->create(['created_by' => $owner->id, 'status' => 'draft']);
        $ordered = PurchaseOrder::factory()->create(['created_by' => $owner->id, 'status' => 'ordered']);

        $this->patchJson("/api/v1/purchase-orders/{$draft->id}/status", ['status' => 'cancelled'])->assertOk();
        $this->patchJson("/api/v1/purchase-orders/{$ordered->id}/status", ['status' => 'cancelled'])->assertOk();
    }

    public function test_out_of_sequence_transition_is_rejected_with_409(): void
    {
        $owner = $this->actingAsRole('owner');
        $draft = PurchaseOrder::factory()->create(['created_by' => $owner->id, 'status' => 'draft']);

        $this->patchJson("/api/v1/purchase-orders/{$draft->id}/status", ['status' => 'received'])->assertStatus(409);
    }

    public function test_re_transitioning_an_already_received_order_to_received_is_rejected(): void
    {
        $owner = $this->actingAsRole('owner');
        $po = PurchaseOrder::factory()->create(['created_by' => $owner->id, 'status' => 'received']);

        $this->patchJson("/api/v1/purchase-orders/{$po->id}/status", ['status' => 'received'])->assertStatus(409);
    }

    public function test_terminal_statuses_reject_any_further_transition(): void
    {
        $owner = $this->actingAsRole('owner');
        $paid = PurchaseOrder::factory()->create(['created_by' => $owner->id, 'status' => 'paid']);
        $cancelled = PurchaseOrder::factory()->create(['created_by' => $owner->id, 'status' => 'cancelled']);

        $this->patchJson("/api/v1/purchase-orders/{$paid->id}/status", ['status' => 'cancelled'])->assertStatus(409);
        $this->patchJson("/api/v1/purchase-orders/{$cancelled->id}/status", ['status' => 'ordered'])->assertStatus(409);
    }
}
