<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 017-company-onboarding — menambah kunci menu 'companies' baru
 * (App\Support\MenuKeys::ALL) ke menu_keys peran default yang sudah
 * ter-seed, mencerminkan persis pola
 * 2026_10_12_000001_add_purchase_orders_menu_key_to_default_roles.
 * HANYA Owner/Admin (BEDA dari purchase_orders yang juga memberi
 * Inventory) — company onboarding adalah kerja sales/ops, bukan kerja
 * inventaris, sesuai FR-012 di spec.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['Owner', 'Admin'] as $roleName) {
            $role = DB::table('roles')->where('name', $roleName)->first();

            if (! $role) {
                continue;
            }

            $menuKeys = json_decode($role->menu_keys, true) ?? [];

            if (! in_array('companies', $menuKeys, true)) {
                $menuKeys[] = 'companies';
                DB::table('roles')->where('id', $role->id)->update([
                    'menu_keys' => json_encode($menuKeys),
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (['Owner', 'Admin'] as $roleName) {
            $role = DB::table('roles')->where('name', $roleName)->first();

            if (! $role) {
                continue;
            }

            $menuKeys = array_values(array_diff(json_decode($role->menu_keys, true) ?? [], ['companies']));
            DB::table('roles')->where('id', $role->id)->update([
                'menu_keys' => json_encode($menuKeys),
            ]);
        }
    }
};
