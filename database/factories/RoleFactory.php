<?php

namespace Database\Factories;

use App\Models\Role;
use App\Support\MenuKeys;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'name' => ucfirst($this->faker->unique()->word()),
            'menu_keys' => ['dashboard', 'pos'],
            'is_system_default' => false,
        ];
    }

    /**
     * Peran yang bisa mengelola pengguna DAN peran — dipakai di test untuk
     * memenuhi/melanggar guard FR-013 dengan jelas.
     */
    public function withUserManagement(): static
    {
        return $this->state(fn () => ['menu_keys' => array_merge(['dashboard'], MenuKeys::RESERVED)]);
    }

    public function full(): static
    {
        return $this->state(fn () => ['menu_keys' => MenuKeys::keys()]);
    }
}
