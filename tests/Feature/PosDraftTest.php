<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosDraftTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function makeVariant(): \App\Models\ProductVariant
    {
        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);

        return $product->variants()->create([
            'sku' => 'DRAFT0001', 'sell_price' => 25000, 'cost_price' => 10000, 'current_stock' => 10, 'is_active' => true,
        ]);
    }

    public function test_a_cashier_can_save_list_resume_and_discard_a_draft(): void
    {
        $this->actingAsRole('cashier');
        $variant = $this->makeVariant();

        $save = $this->postJson('/api/v1/pos-drafts', [
            'items' => [['variant_id' => $variant->id, 'sku' => $variant->sku, 'qty' => 2, 'sell_price' => 25000]],
            'discount_amount' => 1000,
        ]);
        $save->assertCreated()->assertJsonPath('item_count', 1)->assertJsonPath('total', '50000.00');
        $draftId = $save->json('id');

        $this->getJson('/api/v1/pos-drafts')->assertOk()->assertJsonCount(1, 'data');

        $resume = $this->getJson("/api/v1/pos-drafts/{$draftId}");
        $resume->assertOk()
            ->assertJsonPath('items.0.variant_id', $variant->id)
            ->assertJsonPath('items.0.qty', 2)
            ->assertJsonPath('discount_amount', 1000)
            ->assertJsonPath('warnings', []);

        $this->deleteJson("/api/v1/pos-drafts/{$draftId}")->assertNoContent();
        $this->getJson('/api/v1/pos-drafts')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_draft_is_only_visible_to_the_user_who_saved_it(): void
    {
        $this->actingAsRole('cashier');
        $variant = $this->makeVariant();
        $save = $this->postJson('/api/v1/pos-drafts', [
            'items' => [['variant_id' => $variant->id, 'qty' => 1, 'sell_price' => 25000]],
        ]);
        $draftId = $save->json('id');

        $this->actingAsRole('cashier');
        $this->getJson("/api/v1/pos-drafts/{$draftId}")->assertStatus(404);
        $this->deleteJson("/api/v1/pos-drafts/{$draftId}")->assertStatus(404);
    }

    public function test_saving_a_draft_does_not_affect_stock(): void
    {
        $this->actingAsRole('cashier');
        $variant = $this->makeVariant();

        $this->postJson('/api/v1/pos-drafts', [
            'items' => [['variant_id' => $variant->id, 'qty' => 5, 'sell_price' => 25000]],
        ])->assertCreated();

        $this->assertSame(10, $variant->fresh()->current_stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_resuming_a_draft_referencing_a_deactivated_variant_flags_a_warning_instead_of_failing(): void
    {
        $this->actingAsRole('cashier');
        $variant = $this->makeVariant();

        $save = $this->postJson('/api/v1/pos-drafts', [
            'items' => [['variant_id' => $variant->id, 'sku' => $variant->sku, 'qty' => 1, 'sell_price' => 25000]],
        ]);
        $draftId = $save->json('id');

        $variant->update(['is_active' => false]);

        $resume = $this->getJson("/api/v1/pos-drafts/{$draftId}");
        $resume->assertOk()->assertJsonPath('items', []);
        $this->assertNotEmpty($resume->json('warnings'));
    }
}
