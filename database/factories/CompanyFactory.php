<?php

namespace Database\Factories;

use App\Models\BusinessType;
use App\Models\Package;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class CompanyFactory extends Factory
{
    protected $model = \App\Models\Company::class;

    public function definition(): array
    {
        return [
            'business_type_id' => BusinessType::factory(),
            'package_id' => Package::factory(),
            'name' => fake()->company(),
            'address' => fake()->address(),
            'contact_name' => fake()->name(),
            'contact_email' => fake()->safeEmail(),
            'contact_phone' => fake()->phoneNumber(),
            'owner_user_id' => User::factory()->state(['is_active' => false]),
            'status' => 'pending_activation',
            'activation_code_hash' => Hash::make('123456'),
            'activation_code_expires_at' => now()->addHours(24),
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'activated_at' => now(),
        ]);
    }
}
