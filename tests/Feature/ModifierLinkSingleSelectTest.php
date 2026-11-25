<?php

namespace Tests\Feature;

use App\Models\ModifierGroup;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 094 / research D16 C1 — grup pilihan TUNGGAL (`selection_type = single`) hanya boleh ditautkan dengan maksimum 1 dan
 * minimum 0 atau 1. Dulu aturan ini hanya ada di klien web (`modifierLinkRows.js::validateRows`); aplikasi Android sebagai
 * klien kedua membuatnya menjadi jalan nyata untuk menyimpan tautan yang ditolak web. Kini server yang menegakkan.
 */
class ModifierLinkSingleSelectTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private ProductVariant $v;

    private ModifierGroup $single;

    private ModifierGroup $multi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->v = $this->makeVariant(stock: 10, price: 10000);
        $this->single = ModifierGroup::factory()->create(['name' => 'Suhu', 'selection_type' => 'single', 'min_select' => 0, 'max_select' => 1]);
        $this->multi = ModifierGroup::factory()->create(['name' => 'Topping', 'selection_type' => 'multiple', 'min_select' => 0, 'max_select' => 3]);
    }

    private function save(array $link)
    {
        return $this->putJson("/api/v1/products/{$this->v->product_id}/modifier-links", [
            'links' => [array_merge(['is_required' => false, 'display_order' => 1], $link)],
        ]);
    }

    public function test_a_single_group_with_max_one_and_min_zero_or_one_is_accepted(): void
    {
        $this->save(['group_id' => $this->single->id, 'min_select' => 0, 'max_select' => 1])->assertOk();
        $this->save(['group_id' => $this->single->id, 'min_select' => 1, 'max_select' => 1])->assertOk();
    }

    public function test_a_single_group_with_max_above_one_or_without_max_is_refused_on_max_select(): void
    {
        $this->save(['group_id' => $this->single->id, 'min_select' => 0, 'max_select' => 2])
            ->assertStatus(422)->assertJsonValidationErrors('links.0.max_select');
        $this->save(['group_id' => $this->single->id, 'min_select' => 0, 'max_select' => null])
            ->assertStatus(422)->assertJsonValidationErrors('links.0.max_select');
    }

    public function test_a_single_group_with_min_above_one_is_refused(): void
    {
        $this->save(['group_id' => $this->single->id, 'min_select' => 2, 'max_select' => 2])
            ->assertStatus(422)->assertJsonValidationErrors('links.0.max_select');
    }

    public function test_a_multiple_group_keeps_its_wider_limits(): void
    {
        $this->save(['group_id' => $this->multi->id, 'min_select' => 2, 'max_select' => 3])->assertOk();
        $this->save(['group_id' => $this->multi->id, 'min_select' => 0, 'max_select' => null])->assertOk();
    }
}
