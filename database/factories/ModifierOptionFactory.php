<?php

namespace Database\Factories;

use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ModifierOption> */
class ModifierOptionFactory extends Factory
{
    protected $model = ModifierOption::class;

    public function definition(): array
    {
        return [
            'modifier_group_id' => ModifierGroup::factory(),
            'name' => 'Opsi '.fake()->unique()->numberBetween(1, 99999),
            'price_delta' => 0,
            'display_order' => 0,
            'is_active' => true,
        ];
    }
}
