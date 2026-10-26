<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 076-settlement-tab-session-branding — menambah kunci menu 'settlements' baru
 * (App\Support\MenuKeys::ALL) ke menu_keys peran default yang sudah
 * ter-seed lewat migrasi 2026_10_09_000002 (pola yang sama dengan 'purchase_orders'). Kunci baru di MenuKeys::ALL
 * TIDAK otomatis muncul di baris `roles` yang sudah ada — baris itu
 * menyimpan salinan JSON dari MenuKeys::keys() pada SAAT migrasi lama
 * dijalankan, bukan referensi hidup ke registry. Hanya Owner dan Admin
 * yang dapat kunci ini (modul settlement memuat angka keuangan antar-seller);
 * Inventory dan Kasir sengaja TIDAK.
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

            if (! in_array('settlements', $menuKeys, true)) {
                $menuKeys[] = 'settlements';
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

            $menuKeys = array_values(array_diff(json_decode($role->menu_keys, true) ?? [], ['settlements']));
            DB::table('roles')->where('id', $role->id)->update([
                'menu_keys' => json_encode($menuKeys),
            ]);
        }
    }
};
