<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Ditambahkan di akhir sesi 3, setelah modul Product ada — menutup gap
// yang sebelumnya berstatus "Deferred" di RTM Increment 1.
class OutletDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_artist_with_active_product_cannot_be_deleted(): void
    {
        $user = User::factory()->create(['role' => 'owner']);
        $this->actingAs($user, 'sanctum');

        $artist = Outlet::factory()->create();
        $category = Category::factory()->create();
        Product::factory()->create(['home_outlet_id' => $artist->id, 'category_id' => $category->id, 'is_active' => true]);

        $response = $this->deleteJson("/api/v1/outlets/{$artist->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('outlets', ['id' => $artist->id, 'deleted_at' => null]);
    }

    public function test_artist_without_products_can_still_be_deleted(): void
    {
        $user = User::factory()->create(['role' => 'owner']);
        $this->actingAs($user, 'sanctum');
        $artist = Outlet::factory()->create();

        $this->deleteJson("/api/v1/outlets/{$artist->id}")->assertStatus(204);
    }
}
