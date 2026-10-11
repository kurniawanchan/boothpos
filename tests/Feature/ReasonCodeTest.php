<?php

namespace Tests\Feature;

use App\Models\ReasonCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 043-products-page-redesign (T018) — daftar alasan: bawaan terseed, bahasa pemanggil, admin menulis.
 */
class ReasonCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_are_seeded_per_category(): void
    {
        $this->assertSame(4, ReasonCode::where('category', 'sold_out')->count());
        $this->assertSame(4, ReasonCode::where('category', 'discontinue')->count());
        $this->assertSame(3, ReasonCode::where('category', 'approval_reject')->count());
    }

    public function test_any_logged_in_user_can_list_active_reasons_in_their_language(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');

        $row = collect($this->getJson('/api/v1/reason-codes?category=sold_out')->assertOk()->json('data'))->firstWhere('code', 'out_of_stock');
        $this->assertSame('Bahan habis', $row['label']);

        $this->actingAs(User::factory()->create(['role' => 'cashier', 'language' => 'en']), 'sanctum');
        $row = collect($this->getJson('/api/v1/reason-codes?category=sold_out')->json('data'))->firstWhere('code', 'out_of_stock');
        $this->assertSame('Out of stock', $row['label']);
    }

    public function test_inactive_codes_are_hidden_from_everyone_except_admins_who_ask(): void
    {
        ReasonCode::where('category', 'sold_out')->where('code', 'other')->update(['is_active' => false]);

        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');
        $this->assertNotContains('other', array_column($this->getJson('/api/v1/reason-codes?category=sold_out&include_inactive=1')->json('data'), 'code'));

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->assertContains('other', array_column($this->getJson('/api/v1/reason-codes?category=sold_out&include_inactive=1')->json('data'), 'code'));
    }

    public function test_the_owner_can_create_edit_and_deactivate_and_each_is_logged(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $id = $this->postJson('/api/v1/reason-codes', ['category' => 'sold_out', 'code' => 'delivery_late', 'label_id' => 'Pengiriman terlambat', 'label_en' => 'Late delivery'])
            ->assertCreated()->json('id');
        $this->putJson("/api/v1/reason-codes/{$id}", ['label_en' => 'Delivery delayed', 'is_active' => false])->assertOk()->assertJsonPath('is_active', false);

        $this->assertDatabaseHas('activity_logs', ['action' => 'reason_code_created', 'entity_id' => $id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'reason_code_updated', 'entity_id' => $id]);
    }

    public function test_a_cashier_cannot_write_and_duplicates_or_bad_codes_are_refused(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');
        $this->postJson('/api/v1/reason-codes', ['category' => 'sold_out', 'code' => 'x_y', 'label_id' => 'A', 'label_en' => 'A'])->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->postJson('/api/v1/reason-codes', ['category' => 'sold_out', 'code' => 'out_of_stock', 'label_id' => 'A', 'label_en' => 'A'])->assertStatus(422);
        $this->postJson('/api/v1/reason-codes', ['category' => 'sold_out', 'code' => 'Bad Code!', 'label_id' => 'A', 'label_en' => 'A'])->assertStatus(422);
    }
}
