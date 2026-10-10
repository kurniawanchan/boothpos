<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 028-restaurant-dine-in-ordering — memberikan tiga kunci menu baru
 * (App\Support\MenuKeys::ALL) ke peran default yang sudah ter-seed,
 * mencerminkan pola 2026_10_19_000011_add_invoices_menu_key_to_default_roles.
 *
 * Pemetaan (research.md Keputusan 8):
 *   Owner/Admin -> floor, kitchen, dining_setup
 *   Kasir       -> floor, kitchen   (ambil pesanan + layar dapur)
 *   Inventory   -> dining_setup     (meja, stasiun, modifier = master data)
 *
 * Peran buatan toko sendiri TIDAK disentuh — pemilik mengatur lewat layar
 * Peran. Peran default yang namanya sudah diganti dilewati (sama seperti
 * migrasi-migrasi pendahulunya).
 */
return new class extends Migration
{
    private const GRANTS = [
        'Owner' => ['floor', 'kitchen', 'dining_setup'],
        'Admin' => ['floor', 'kitchen', 'dining_setup'],
        'Kasir' => ['floor', 'kitchen'],
        'Inventory' => ['dining_setup'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $roleName => $keys) {
            $role = DB::table('roles')->where('name', $roleName)->first();

            if (! $role) {
                continue;
            }

            $menuKeys = json_decode($role->menu_keys, true) ?? [];

            foreach ($keys as $key) {
                if (! in_array($key, $menuKeys, true)) {
                    $menuKeys[] = $key;
                }
            }

            DB::table('roles')->where('id', $role->id)->update(['menu_keys' => json_encode($menuKeys)]);
        }
    }

    public function down(): void
    {
        // BUG YANG DITEMUKAN & DIPERBAIKI (verifikasi migrasi pada data nyata): rollback berjalan dari
        // migrasi TERAKHIR ke yang pertama, tetapi penjaga "masih ada tab terbuka" semula hanya ada di
        // 2026_10_31_000006 — jadi saat ia menolak, migrasi 000008 dan 000007 (yang MENGHAPUS tabel tiket
        // dapur) sudah berjalan: tiket tab yang masih berjalan hilang dan skema tinggal setengah.
        // DDL MySQL tidak ikut transaksi, jadi penolakan harus terjadi SEBELUM perubahan apa pun.
        if (\Illuminate\Support\Facades\DB::table('orders')->where('status', 'open')->exists()) {
            throw new \RuntimeException('Rollback ditolak: masih ada tab terbuka (orders.status = open). Selesaikan atau batalkan dulu.');
        }

        foreach (self::GRANTS as $roleName => $keys) {
            $role = DB::table('roles')->where('name', $roleName)->first();

            if (! $role) {
                continue;
            }

            $menuKeys = array_values(array_diff(json_decode($role->menu_keys, true) ?? [], $keys));

            DB::table('roles')->where('id', $role->id)->update(['menu_keys' => json_encode($menuKeys)]);
        }
    }
};
