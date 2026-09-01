<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    // --- Positive ---------------------------------------------------

    public function test_owner_can_create_category(): void
    {
        $this->actingAsRole('owner');

        $response = $this->postJson('/api/v1/categories', [
            'code' => 'ky',
            'name' => 'Keychain',
        ]);

        $response->assertCreated()->assertJsonPath('code', 'KY');
    }

    public function test_category_can_have_a_parent(): void
    {
        $this->actingAsRole('owner');
        $parent = Category::factory()->create(['code' => 'AC']);

        $response = $this->postJson('/api/v1/categories', [
            'code' => 'KY',
            'name' => 'Keychain',
            'parent_id' => $parent->id,
        ]);

        $response->assertCreated()->assertJsonPath('parent_id', $parent->id);
    }

    // --- Negative: validasi dasar ---------------------------------------

    public function test_code_must_be_exactly_two_letters(): void
    {
        $this->actingAsRole('owner');

        $response = $this->postJson('/api/v1/categories', [
            'code' => 'ABC',
            'name' => 'Test',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_parent_id_must_reference_existing_category(): void
    {
        $this->actingAsRole('owner');

        $response = $this->postJson('/api/v1/categories', [
            'code' => 'KY',
            'name' => 'Keychain',
            'parent_id' => 9999,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_code_cannot_be_changed_on_update(): void
    {
        $this->actingAsRole('owner');
        $category = Category::factory()->create(['code' => 'KY']);

        $response = $this->putJson("/api/v1/categories/{$category->id}", [
            'code' => 'ZZ',
            'name' => 'Nama Baru',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('code');
    }

    // --- Negative: otorisasi -------------------------------------------

    public function test_cashier_cannot_create_category(): void
    {
        $this->actingAsRole('cashier');

        $this->postJson('/api/v1/categories', [
            'code' => 'KY',
            'name' => 'Keychain',
        ])->assertStatus(403);
    }

    // --- Negative: pencegahan siklus (business rule paling kritis di modul ini) ---

    public function test_category_cannot_be_its_own_parent(): void
    {
        $this->actingAsRole('owner');
        $category = Category::factory()->create();

        $response = $this->putJson("/api/v1/categories/{$category->id}", [
            'name' => $category->name,
            'parent_id' => $category->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_setting_parent_to_own_descendant_is_rejected(): void
    {
        $this->actingAsRole('owner');

        // Rantai: grandparent -> parent -> child
        $grandparent = Category::factory()->create(['code' => 'AA']);
        $parent = Category::factory()->create(['code' => 'BB', 'parent_id' => $grandparent->id]);
        $child = Category::factory()->create(['code' => 'CC', 'parent_id' => $parent->id]);

        // Mencoba menjadikan grandparent sebagai anak dari child-nya sendiri
        $response = $this->putJson("/api/v1/categories/{$grandparent->id}", [
            'name' => $grandparent->name,
            'parent_id' => $child->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('parent_id');

        // Data tidak berubah sama sekali
        $this->assertDatabaseHas('categories', [
            'id' => $grandparent->id,
            'parent_id' => null,
        ]);
    }

    public function test_reassigning_to_unrelated_parent_succeeds(): void
    {
        $this->actingAsRole('owner');
        $categoryA = Category::factory()->create(['code' => 'AA']);
        $categoryB = Category::factory()->create(['code' => 'BB']);

        $response = $this->putJson("/api/v1/categories/{$categoryB->id}", [
            'name' => $categoryB->name,
            'parent_id' => $categoryA->id,
        ]);

        $response->assertOk()->assertJsonPath('parent_id', $categoryA->id);
    }

    // --- Negative: business rule hapus ----------------------------------

    public function test_category_with_active_child_cannot_be_deleted(): void
    {
        $this->actingAsRole('owner');
        $parent = Category::factory()->create();
        Category::factory()->create(['parent_id' => $parent->id, 'is_active' => true]);

        $response = $this->deleteJson("/api/v1/categories/{$parent->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('categories', ['id' => $parent->id, 'deleted_at' => null]);
    }

    public function test_category_with_only_inactive_children_can_be_deleted(): void
    {
        $this->actingAsRole('owner');
        $parent = Category::factory()->create();
        Category::factory()->create(['parent_id' => $parent->id, 'is_active' => false]);

        $this->deleteJson("/api/v1/categories/{$parent->id}")->assertStatus(204);
    }

    public function test_category_without_children_can_be_deleted(): void
    {
        $this->actingAsRole('owner');
        $category = Category::factory()->create();

        $this->deleteJson("/api/v1/categories/{$category->id}")->assertStatus(204);
    }

    // =====================================================================
    // Task 5 — unggah gambar kategori.
    // =====================================================================

    public function test_owner_can_upload_a_category_image(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->actingAsRole('owner');
        $category = Category::factory()->create();

        $response = $this->post("/api/v1/categories/{$category->id}/image", [
            'image' => \Illuminate\Http\UploadedFile::fake()->image('kategori.png'),
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('image_url'));
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($category->fresh()->image_path);
    }

    public function test_cashier_cannot_upload_a_category_image(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->actingAsRole('cashier');
        $category = Category::factory()->create();

        $response = $this->post("/api/v1/categories/{$category->id}/image", [
            'image' => \Illuminate\Http\UploadedFile::fake()->image('kategori.png'),
        ]);

        $response->assertStatus(403);
    }
}
