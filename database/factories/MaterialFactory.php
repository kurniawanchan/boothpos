<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class MaterialFactory extends Factory
{
    protected $model = \App\Models\Material::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('MT###')),
            'name' => fake()->words(2, true),
            'unit' => fake()->randomElement(['pcs', 'gram', 'meter', 'lembar']),
            'notes' => null,
            'is_active' => true,
        ];
    }
}
