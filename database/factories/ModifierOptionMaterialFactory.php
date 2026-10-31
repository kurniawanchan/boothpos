<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\ModifierOption;
use App\Models\ModifierOptionMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ModifierOptionMaterial> */
class ModifierOptionMaterialFactory extends Factory
{
    protected $model = ModifierOptionMaterial::class;

    public function definition(): array
    {
        return [
            'modifier_option_id' => ModifierOption::factory(),
            'material_id' => Material::factory(),
            'quantity' => 1,
        ];
    }
}
