<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 005-ux-enhancements-dashboard (US1) — GET /products sekarang menerima
 * artist_id/category_id sebagai array (multi-select dropdown), bukan lagi
 * skalar tunggal. Lihat ProductController::index() dan research.md R3.
 */
class ProductFilterMultiValueTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_multi_value_artist_id_filters_with_or_within_axis(): void
    {
        $this->actingAsRole('owner');

        $artistA = Artist::factory()->create();
        $artistB = Artist::factory()->create();
        $artistC = Artist::factory()->create();
        $category = Category::factory()->create();

        $productA = Product::factory()->create(['artist_id' => $artistA->id, 'category_id' => $category->id]);
        $productB = Product::factory()->create(['artist_id' => $artistB->id, 'category_id' => $category->id]);
        $productC = Product::factory()->create(['artist_id' => $artistC->id, 'category_id' => $category->id]);

        $response = $this->getJson('/api/v1/products?'.http_build_query(['artist_id' => [$artistA->id, $artistB->id]]));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($productA->id));
        $this->assertTrue($ids->contains($productB->id));
        $this->assertFalse($ids->contains($productC->id));
    }

    public function test_combined_artist_and_category_arrays_apply_and_across_axis(): void
    {
        $this->actingAsRole('owner');

        $artistA = Artist::factory()->create();
        $artistB = Artist::factory()->create();
        $categoryX = Category::factory()->create();
        $categoryY = Category::factory()->create();

        // Cocok kedua sumbu.
        $match = Product::factory()->create(['artist_id' => $artistA->id, 'category_id' => $categoryX->id]);
        // Cocok artist saja.
        $artistOnly = Product::factory()->create(['artist_id' => $artistA->id, 'category_id' => $categoryY->id]);
        // Cocok category saja.
        $categoryOnly = Product::factory()->create(['artist_id' => $artistB->id, 'category_id' => $categoryX->id]);

        $response = $this->getJson('/api/v1/products?'.http_build_query([
            'artist_id' => [$artistA->id],
            'category_id' => [$categoryX->id],
        ]));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($match->id));
        $this->assertFalse($ids->contains($artistOnly->id));
        $this->assertFalse($ids->contains($categoryOnly->id));
    }

    public function test_bare_scalar_artist_id_still_filters_for_backward_compatibility(): void
    {
        $this->actingAsRole('owner');

        $artistA = Artist::factory()->create();
        $artistB = Artist::factory()->create();
        $category = Category::factory()->create();

        $productA = Product::factory()->create(['artist_id' => $artistA->id, 'category_id' => $category->id]);
        $productB = Product::factory()->create(['artist_id' => $artistB->id, 'category_id' => $category->id]);

        $response = $this->getJson('/api/v1/products?artist_id='.$artistA->id);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($productA->id));
        $this->assertFalse($ids->contains($productB->id));
    }
}
