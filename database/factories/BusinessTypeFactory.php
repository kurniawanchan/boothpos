<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class BusinessTypeFactory extends Factory
{
    protected $model = \App\Models\BusinessType::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'code' => strtoupper(fake()->unique()->bothify('BT###')),
            'is_active' => true,
        ];
    }
}
