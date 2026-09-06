<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PackageFactory extends Factory
{
    protected $model = \App\Models\Package::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'license_tier' => fake()->randomElement(['pro', 'master']),
            'is_active' => true,
        ];
    }
}
