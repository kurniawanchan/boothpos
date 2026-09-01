<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

class VendorMaterialPriceFactory extends Factory
{
    protected $model = \App\Models\VendorMaterialPrice::class;

    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'material_id' => Material::factory(),
            'price' => fake()->randomFloat(2, 500, 50000),
            'is_preferred' => false,
            'notes' => null,
        ];
    }
}
