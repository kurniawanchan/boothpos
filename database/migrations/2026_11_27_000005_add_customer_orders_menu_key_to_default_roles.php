<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Customer commerce — menambah kunci menu 'customer_orders' (App\Support\MenuKeys::ALL)
 * ke menu_keys peran default yang sudah ter-seed lewat migrasi 2026_10_09_000002
 * (pola yang sama dengan 'settlements' / 'purchase_orders'). Kunci baru di
 * MenuKeys::ALL TIDAK otomatis muncul di baris `roles` yang sudah ada: baris itu
 * menyimpan salinan JSON dari MenuKeys::keys() pada SAAT migrasi lama dijalankan,
 * bukan referensi hidup ke registry — dan Role::allows() menolak menu yang tidak
 * ada di menu_keys bahkan untuk Owner.
 *
 * Hanya Owner dan Admin yang mendapat kunci ini (keputusan bawaan yang konservatif:
 * mengonfirmasi pesanan menggerakkan stok dan membatalkannya mengembalikan stok).
 * Kasir dan Inventory sengaja TIDAK; pemilik toko bisa menambahkannya sendiri lewat
 * editor peran. `menu_actions` tidak disentuh: NULL berarti semua aksi menu (aturan 057).
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

            if (! in_array('customer_orders', $menuKeys, true)) {
                $menuKeys[] = 'customer_orders';
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

            $menuKeys = array_values(array_diff(json_decode($role->menu_keys, true) ?? [], ['customer_orders']));
            DB::table('roles')->where('id', $role->id)->update([
                'menu_keys' => json_encode($menuKeys),
            ]);
        }
    }
};
