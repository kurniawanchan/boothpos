<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 090-self-service-qr-ordering — kunci menu baru `self_service` (App\Support\MenuKeys::ALL) untuk tab
 * Self-Service di Penjualan. Pola 2026_10_31_000008: baris `roles` menyimpan SALINAN JSON kunci pada saat
 * migrasi lama berjalan, jadi kunci baru tidak muncul otomatis di peran default yang sudah ter-seed.
 *
 * Owner/Admin/Kasir mendapat kunci ini (kasir yang memverifikasi dan mengirim ke dapur). Inventory tidak.
 * Peran buatan toko sendiri TIDAK disentuh — pemilik mengaturnya lewat layar Peran.
 */
return new class extends Migration
{
    private const KEY = 'self_service';

    private const ROLES = ['Owner', 'Admin', 'Kasir'];

    public function up(): void
    {
        foreach (self::ROLES as $roleName) {
            $role = DB::table('roles')->where('name', $roleName)->first();

            if (! $role) {
                continue;
            }

            $menuKeys = json_decode($role->menu_keys, true) ?? [];

            if (! in_array(self::KEY, $menuKeys, true)) {
                $menuKeys[] = self::KEY;
                DB::table('roles')->where('id', $role->id)->update(['menu_keys' => json_encode($menuKeys)]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::ROLES as $roleName) {
            $role = DB::table('roles')->where('name', $roleName)->first();

            if (! $role) {
                continue;
            }

            $menuKeys = array_values(array_diff(json_decode($role->menu_keys, true) ?? [], [self::KEY]));
            DB::table('roles')->where('id', $role->id)->update(['menu_keys' => json_encode($menuKeys)]);
        }
    }
};
