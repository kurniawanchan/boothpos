<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = \App\Models\User::class;

    /**
     * Nilai enum lama (owner/admin/cashier/inventory) dipetakan ke nama
     * Role default yang di-seed migrasi
     * 2026_10_09_000002_add_role_id_and_photo_to_users_table, dengan
     * menu_keys yang identik — supaya lusinan test lama di tests/Feature
     * yang menulis User::factory()->create(['role' => 'owner']) (dan
     * sejenisnya) tetap berjalan tanpa diubah satu per satu, sekaligus
     * tetap mendapatkan hak akses yang persis sama dengan peran seed
     * produksi. Ini SATU-SATUNYA tempat pemetaan ini didefinisikan untuk
     * kebutuhan test — jangan diduplikasi di file test manapun.
     */
    private const LEGACY_ROLE_MENU_KEYS = [
        'owner' => ['name' => 'Owner', 'menu_keys' => [
            'dashboard', 'pos', 'session', 'events', 'products', 'artists', 'categories',
            'stock', 'vendors', 'materials', 'customers', 'preorders', 'sales', 'reports',
            'users', 'roles', 'settings',
        ]],
        'admin' => ['name' => 'Admin', 'menu_keys' => [
            'dashboard', 'pos', 'session', 'events', 'products', 'artists', 'categories',
            'stock', 'vendors', 'materials', 'customers', 'preorders', 'sales', 'reports',
            'users', 'roles', 'settings',
        ]],
        'cashier' => ['name' => 'Kasir', 'menu_keys' => [
            'dashboard', 'pos', 'session', 'events', 'customers', 'preorders', 'sales',
        ]],
        'inventory' => ['name' => 'Inventory', 'menu_keys' => [
            'dashboard', 'pos', 'session', 'events', 'products', 'artists', 'categories',
            'stock', 'vendors', 'materials', 'customers', 'preorders', 'sales',
        ]],
    ];

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'password' => Hash::make('password'),
            'role_id' => $this->roleIdFor('cashier'),
            'is_active' => true,
        ];
    }

    public function create($attributes = [], ?Model $parent = null)
    {
        return parent::create($this->resolveLegacyRoleAttribute($attributes), $parent);
    }

    public function make($attributes = [], ?Model $parent = null)
    {
        return parent::make($this->resolveLegacyRoleAttribute($attributes), $parent);
    }

    /**
     * Menerima 'role' => 'owner'|'admin'|'cashier'|'inventory' (bentuk
     * lama) dan menerjemahkannya ke 'role_id' yang menunjuk baris Role
     * dengan menu_keys yang sepadan, idempoten via firstOrCreate. Kunci
     * 'role_id' eksplisit tetap diprioritaskan bila keduanya dikirim.
     */
    private function resolveLegacyRoleAttribute(array $attributes): array
    {
        if (isset($attributes['role']) && is_string($attributes['role'])) {
            $attributes['role_id'] ??= $this->roleIdFor($attributes['role']);
            unset($attributes['role']);
        }

        return $attributes;
    }

    private function roleIdFor(string $legacyRole): int
    {
        $definition = self::LEGACY_ROLE_MENU_KEYS[$legacyRole] ?? self::LEGACY_ROLE_MENU_KEYS['cashier'];

        return Role::firstOrCreate(
            ['name' => $definition['name']],
            ['menu_keys' => $definition['menu_keys'], 'is_system_default' => true]
        )->id;
    }
}
