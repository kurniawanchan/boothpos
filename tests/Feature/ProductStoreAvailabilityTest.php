<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\OutletMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 070 (US4) — bagian Ketersediaan di formulir tambah: outlet pembawa dikirim sebagai `outlet_ids`. Saat MEMBUAT, daftar
 * yang dikirim tetapi kosong ditolak (item tak dijual di mana pun); tanpa `outlet_ids` perilaku lama (semua outlet).
 */
class ProductStoreAvailabilityTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private $a;

    private $b;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->a, $this->b] = $this->makeOutlets(2)->all();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'home_outlet_id' => $this->a->id,
            'category_id' => Category::factory()->create()->id,
            'name' => 'Mie Level 1-2',
            'variants' => [['variant_name' => 'Standard', 'sell_price' => 15000]],
        ], $extra);
    }

    public function test_an_empty_outlet_list_is_refused_when_creating(): void
    {
        $this->postJson('/api/v1/products', $this->payload(['outlet_ids' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors('outlet_ids');

        $this->assertSame(0, Product::count());
    }

    public function test_only_the_chosen_outlet_carries_the_item(): void
    {
        $id = $this->postJson('/api/v1/products', $this->payload(['outlet_ids' => [$this->b->id]]))->assertCreated()->json('id');

        $this->assertSame([$this->b->id], app(OutletMenuService::class)->carriedOutletIds(Product::findOrFail($id)));
    }

    public function test_without_outlet_ids_every_outlet_carries_the_item(): void
    {
        $id = $this->postJson('/api/v1/products', $this->payload())->assertCreated()->json('id');

        $this->assertSame([$this->a->id, $this->b->id], app(OutletMenuService::class)->carriedOutletIds(Product::findOrFail($id)));
    }

    public function test_an_outlet_the_user_may_not_see_is_still_refused(): void
    {
        $user = $this->assignUser(User::factory()->create(['role' => 'inventory']), [$this->a]);
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/v1/products', $this->payload(['outlet_ids' => [$this->b->id]]))
            ->assertUnprocessable()->assertJsonValidationErrors('outlet_ids.0');
    }

    public function test_updating_is_not_affected_by_the_create_only_rule(): void
    {
        $id = $this->postJson('/api/v1/products', $this->payload())->assertCreated()->json('id');

        // Ubah item (UpdateProductRequest) tetap memakai aturan lama: perilaku ubah tidak berubah oleh 070.
        $this->putJson("/api/v1/products/{$id}", ['name' => 'Mie Level 3-4', 'outlet_ids' => [$this->a->id]])->assertOk();
        $this->assertSame([$this->a->id], app(OutletMenuService::class)->carriedOutletIds(Product::findOrFail($id)));
    }
}
