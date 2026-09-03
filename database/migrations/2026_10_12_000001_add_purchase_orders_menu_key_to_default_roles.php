<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 006-purchase-order-and-ops — menambah kunci menu 'purchase_orders' baru
 * (App\Support\MenuKeys::ALL) ke menu_keys peran default yang sudah
 * ter-seed lewat migrasi 2026_10_09_000002. Kunci baru di MenuKeys::ALL
 * TIDAK otomatis muncul di baris `roles` yang sudah ada — baris itu
 * menyimpan salinan JSON dari MenuKeys::keys() pada SAAT migrasi lama
 * dijalankan, bukan referensi hidup ke registry. Owner/Admin/Inventory
 * dapat kunci ini (sama seperti mereka sudah punya 'vendors'/'materials');
 * Kasir sengaja TIDAK, konsisten dengan pola Kasir tidak punya akses
 * master data lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['Owner', 'Admin', 'Inventory'] as $roleName) {
            $role = DB::table('roles')->where('name', $roleName)->first();

            if (! $role) {
                continue;
            }

            $menuKeys = json_decode($role->menu_keys, true) ?? [];

            if (! in_array('purchase_orders', $menuKeys, true)) {
                $menuKeys[] = 'purchase_orders';
                DB::table('roles')->where('id', $role->id)->update([
                    'menu_keys' => json_encode($menuKeys),
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (['Owner', 'Admin', 'Inventory'] as $roleName) {
            $role = DB::table('roles')->where('name', $roleName)->first();

            if (! $role) {
                continue;
            }

            $menuKeys = array_values(array_diff(json_decode($role->menu_keys, true) ?? [], ['purchase_orders']));
            DB::table('roles')->where('id', $role->id)->update([
                'menu_keys' => json_encode($menuKeys),
            ]);
        }
    }
};
