<?php

namespace Database\Factories;

use App\Models\Outlet;
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
        'owner' => ['name' => 'Owner', 'all_outlets' => true, 'menu_keys' => [
            'dashboard', 'pos', 'session', 'events', 'products', 'outlets', 'categories',
            'stock', 'vendors', 'materials', 'customers', 'preorders', 'sales', 'reports',
            'users', 'roles', 'settings',
        ]],
        'admin' => ['name' => 'Admin', 'all_outlets' => true, 'menu_keys' => [
            'dashboard', 'pos', 'session', 'events', 'products', 'outlets', 'categories',
            'stock', 'vendors', 'materials', 'customers', 'preorders', 'sales', 'reports',
            'users', 'roles', 'settings',
        ]],
        'cashier' => ['name' => 'Kasir', 'menu_keys' => [
            'dashboard', 'pos', 'session', 'events', 'customers', 'preorders', 'sales',
        ]],
        'inventory' => ['name' => 'Inventory', 'menu_keys' => [
            'dashboard', 'pos', 'session', 'events', 'products', 'outlets', 'categories',
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

    /**
     * 045 Stage 2 — pengguna non-admin yang dibuat tes mendapat SEMUA outlet aktif, supaya tes lama yang
     * bertindak sebagai kasir tetap melihat datanya sendiri (produksi tidak melakukan ini; penugasan
     * di produksi eksplisit lewat PUT /users/{user}/outlets). Yang butuh pengguna tanpa penugasan
     * memakai state unassigned().
     */
    public function configure(): static
    {
        return $this->afterCreating(function (\App\Models\User $user) {
            if (! $user->hasAllOutlets()) {
                $user->outlets()->syncWithoutDetaching(Outlet::query()->where('is_active', true)->pluck('id')->all());
            }
        });
    }

    /** Pengguna non-admin tanpa penugasan outlet (untuk tes penolakan 409 outlet_unassigned dan cakupan kosong). */
    public function unassigned(): static
    {
        return $this->afterCreating(fn (\App\Models\User $user) => $user->outlets()->detach());
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
            ['menu_keys' => $definition['menu_keys'], 'is_system_default' => true, 'all_outlets' => $definition['all_outlets'] ?? false]
        )->id;
    }
}
