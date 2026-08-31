<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentChannelFactory extends Factory
{
    protected $model = \App\Models\PaymentChannel::class;

    public function definition(): array
    {
        return [
            'type' => 'bank_transfer',
            'provider' => fake()->randomElement(['BCA', 'Mandiri']),
            'account_name' => fake()->name(),
            'account_number' => fake()->numerify('##########'),
            'display_order' => 0,
            'is_active' => true,
        ];
    }
}
