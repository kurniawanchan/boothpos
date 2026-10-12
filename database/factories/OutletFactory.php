<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OutletFactory extends Factory
{
    protected $model = \App\Models\Outlet::class;

    /** Setiap outlet baru terlihat oleh pengguna non-admin yang sudah ada (lihat UserFactory::configure). */
    public function configure(): static
    {
        return $this->afterCreating(function (\App\Models\Outlet $outlet) {
            User::with('role')->get()
                ->reject(fn (User $user) => $user->hasAllOutlets())
                ->each(fn (User $user) => $user->outlets()->syncWithoutDetaching([$outlet->id]));
        });
    }

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
