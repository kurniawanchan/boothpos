<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class LicenseFactory extends Factory
{
    protected $model = \App\Models\License::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'license_tier' => fake()->randomElement(['pro', 'master']),
            'price' => fake()->randomElement([500000, 1500000, 5000000]),
            'payment_type' => fake()->randomElement(['one_time', 'subscription']),
            'is_active' => true,
        ];
    }
}
