<?php

namespace Tests\Feature\OrderApproval;

use App\Models\OrderApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 064 (US4) — batas per aksi dibaca dari config, ditegakkan di pratinjau DAN penerapan. */
class BulkLimitTest extends TestCase
{
    use OrderApprovalFixture, RefreshDatabase;

    public function test_over_the_limit_is_a_422_naming_the_limit_on_both_endpoints(): void
    {
        Storage::fake('local');
        config(['approvals.bulk_max' => 3]);
        $w = $this->buildApprovalWorld();
        $ids = array_map(fn ($o) => $o->id, $this->makeSalesOfA($w, 4));
        $this->actingAs($w['userA'], 'sanctum');

        $this->bulkPreview($ids)->assertStatus(422)->assertJsonValidationErrors(['order_ids'])
            ->assertJsonPath('errors.order_ids.0', __('approvals.bulk_limit_exceeded', ['max' => 3]));
        $this->bulkApply($ids, 'stamp', [])->assertStatus(422)->assertJsonValidationErrors(['order_ids']);

        $this->assertSame(0, OrderApproval::count());
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->bulkApply(array_slice($ids, 0, 3))->assertOk()->assertJsonPath('data.approved_orders', 3); // tepat di batas lolos
    }

    public function test_every_signed_in_role_is_told_the_limit_through_the_features_payload(): void
    {
        config(['approvals.bulk_max' => 7]);
        $w = $this->buildApprovalWorld();

        foreach (['owner', 'cashier', 'userA'] as $who) {
            $this->actingAs($w[$who], 'sanctum');
            $this->getJson('/api/v1/settings/features')->assertOk()->assertJsonPath('bulk_approve_max', 7);
        }
    }

    public function test_the_default_limit_is_200(): void
    {
        $this->assertSame(200, config('approvals.bulk_max'));
    }
}
