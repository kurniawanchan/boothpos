<?php

namespace Database\Factories;

use App\Models\ModifierGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ModifierGroup> */
class ModifierGroupFactory extends Factory
{
    protected $model = ModifierGroup::class;

    public function definition(): array
    {
        return [
            'name' => 'Grup '.fake()->unique()->numberBetween(1, 99999),
            'selection_type' => 'single',
            'is_required' => false,
            'min_select' => 0,
            'max_select' => 1,
            'display_order' => 0,
            'is_active' => true,
        ];
    }
}
