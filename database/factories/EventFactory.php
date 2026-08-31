<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class EventFactory extends Factory
{
    protected $model = \App\Models\Event::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('now', '+2 months');

        return [
            'name' => 'Event '.fake()->words(2, true),
            'location' => fake()->city(),
            'start_date' => $start,
            'end_date' => (clone $start)->modify('+1 day'),
            'status' => 'draft',
            'event_cost' => 0,
        ];
    }
}
