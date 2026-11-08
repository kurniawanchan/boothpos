<?php

namespace Tests\Feature\OrderApproval;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 064 — `can_approve_sellers` di /auth/me: satu definisi server, dasar tombol "Setujui massal". */
class CanApproveSellersFlagTest extends TestCase
{
    use OrderApprovalFixture, RefreshDatabase;

    public function test_flag_follows_the_single_server_definition(): void
    {
        $w = $this->buildApprovalWorld();

        foreach (['owner' => true, 'admin' => true, 'userA' => true, 'cashier' => false] as $key => $expected) {
            $this->actingAs($w[$key], 'sanctum');
            $this->assertSame($expected, $this->getJson('/api/v1/auth/me')->assertOk()->json('can_approve_sellers'), $key);
        }
    }
}
