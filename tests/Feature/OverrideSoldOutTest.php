<?php

namespace Tests\Feature;

use App\Models\ReasonCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** 048 / S3 — tanda habis (sold out) oleh peran tanpa menu.availability.set hanya lewat persetujuan `availability`. */
class OverrideSoldOutTest extends TestCase
{
    use OverrideFixtures;
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;
    private int $outletId;
    private int $reasonId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $product = $this->makeVariant(stock: 10, price: 10000)->product;
        $this->productId = $product->id;
        // Outlet yang dipakai gerbang saat tanpa X-Outlet-Id: satu-satunya outlet aktif.
        $this->outletId = (int) \App\Support\OutletDefaults::forNewRecord();
        $this->cashier->outlets()->syncWithoutDetaching([$this->outletId]);
        $this->reasonId = (int) ReasonCode::where('category', 'sold_out')->orderBy('sort')->value('id');
    }

    private function soldOut(array $body = [])
    {
        return $this->postJson("/api/v1/products/{$this->productId}/sold-out", ['reason_code_id' => $this->reasonId] + $body);
    }

    public function test_marking_sold_out_without_approval_is_refused_and_leaves_no_mark(): void
    {
        $this->soldOut()->assertStatus(409)->assertJsonPath('code', 'override_required');
        $this->assertDatabaseCount('item_availability_marks', 0);
    }

    public function test_an_approved_mark_is_created_once_and_the_token_cannot_be_reused(): void
    {
        $subject = ['product_id' => $this->productId, 'reason_code_id' => $this->reasonId, 'note' => null, 'outlet_ids' => null];
        $token = $this->overrideToken('availability', $subject, $this->outletId);

        $this->soldOut(['override_token' => $token])->assertCreated();
        $this->assertDatabaseCount('item_availability_marks', 1);

        $this->soldOut(['override_token' => $token])->assertStatus(409)->assertJsonPath('code', 'override_expired');
        $this->assertDatabaseCount('item_availability_marks', 1);
    }

    public function test_an_approval_for_another_reason_cannot_mark_the_product(): void
    {
        $other = (int) ReasonCode::where('category', 'sold_out')->orderByDesc('sort')->value('id');
        $token = $this->overrideToken('availability', ['product_id' => $this->productId, 'reason_code_id' => $other, 'note' => null, 'outlet_ids' => null], $this->outletId);

        $this->soldOut(['override_token' => $token])->assertStatus(422)->assertJsonPath('code', 'override_mismatch');
        $this->assertDatabaseCount('item_availability_marks', 0);
    }
}
