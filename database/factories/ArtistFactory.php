<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ArtistFactory extends Factory
{
    protected $model = \App\Models\Artist::class;

    public function definition(): array
    {
        return [
            // 3 huruf acak unik agar aman dipakai berkali-kali dalam satu
            // test run tanpa tabrakan unique constraint.
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->company(),
            'contact_phone' => fake()->phoneNumber(),
            'contact_email' => fake()->safeEmail(),
            'is_active' => true,
        ];
    }
}
