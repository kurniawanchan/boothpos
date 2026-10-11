<?php

namespace Database\Factories;

use App\Models\DiningTable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiningTable>
 */
class DiningTableFactory extends Factory
{
    protected $model = DiningTable::class;

    public function definition(): array
    {
        return [
            'name' => (string) fake()->unique()->numberBetween(1, 99999),
            'area' => '',
            'seats' => 4,
            'display_order' => 0,
            'is_active' => true,
        ];
    }

    public function inArea(string $area): static
    {
        return $this->state(fn () => ['area' => $area]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
