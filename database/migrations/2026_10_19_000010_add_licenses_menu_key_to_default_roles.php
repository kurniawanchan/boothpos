<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 019-billing-system — menambah kunci menu 'licenses' baru
 * (App\Support\MenuKeys::ALL) ke menu_keys peran default yang sudah
 * ter-seed, mencerminkan persis pola
 * 2026_10_16_000005_add_companies_menu_key_to_default_roles.
 * HANYA Owner/Admin — katalog lisensi/harga adalah kerja sales/ops,
 * bukan kerja kasir/inventaris (research.md R2').
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

            if (! in_array('licenses', $menuKeys, true)) {
                $menuKeys[] = 'licenses';
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

            $menuKeys = array_values(array_diff(json_decode($role->menu_keys, true) ?? [], ['licenses']));
            DB::table('roles')->where('id', $role->id)->update([
                'menu_keys' => json_encode($menuKeys),
            ]);
        }
    }
};
