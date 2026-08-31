<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CashierSessionFactory extends Factory
{
    protected $model = \App\Models\CashierSession::class;

    public function definition(): array
    {
        return [
            'event_id' => \App\Models\Event::factory(),
            'user_id' => \App\Models\User::factory(),
            'opened_at' => now(),
            'opening_cash' => 0,
            'status' => 'open',
        ];
    }
}
