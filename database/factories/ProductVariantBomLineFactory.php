<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductVariantBomLineFactory extends Factory
{
    protected $model = \App\Models\ProductVariantBomLine::class;

    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory(),
            'material_id' => Material::factory(),
            'qty_needed' => fake()->randomFloat(4, 0.5, 10),
            'notes' => null,
        ];
    }
}
