<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class VendorFactory extends Factory
{
    protected $model = \App\Models\Vendor::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('VN###')),
            'name' => fake()->company(),
            'contact_phone' => fake()->phoneNumber(),
            'contact_email' => fake()->safeEmail(),
            'notes' => null,
            'is_active' => true,
        ];
    }
}
