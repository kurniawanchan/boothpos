<?php

namespace App\Support;

/**
 * Registry tunggal untuk seluruh menu/layar yang ada di aplikasi hari ini.
 * Ini BUKAN tabel database — inilah sumber kebenaran yang divalidasi oleh
 * Role::menu_keys (lihat StoreRoleRequest/UpdateRoleRequest) dan yang
 * dikembalikan RoleController::menuKeys() supaya layar pengaturan peran
 * bisa merender checkbox tanpa daftar menu di-hardcode dua kali (backend
 * dan frontend).
 *
 * Daftar ini disalin persis dari NAV_DEFS di
 * resources/js/components/layout/AppSidebar.vue pada saat fitur ini
 * dibangun (2026-10) — menambah layar baru ke aplikasi berarti menambah
 * satu entri di sini, bukan migrasi database.
 */
class MenuKeys
{
    /**
     * key => label manusiawi (Bahasa Indonesia, konsisten dengan konvensi
     * UI copy kodebase ini).
     */
    public const ALL = [
        'dashboard' => 'Beranda',
        'pos' => 'Kasir',
        'session' => 'Sesi Kasir',
        'events' => 'Event',
        'products' => 'Produk',
        'artists' => 'Artist',
        'categories' => 'Kategori',
        'stock' => 'Stok',
        'vendors' => 'Vendor',
        'materials' => 'Bahan Baku',
        'purchase_orders' => 'Purchase Order',
        'customers' => 'Pelanggan',
        'preorders' => 'Pre-order',
        'sales' => 'Penjualan',
        'reports' => 'Laporan',
        'users' => 'Pengguna',
        'roles' => 'Peran',
        'settings' => 'Pengaturan',
    ];

    /**
     * Kunci yang dilindungi FR-013 — toko harus selalu punya minimal satu
     * peran yang mencakup KEDUA kunci ini, supaya tidak ada instalasi yang
     * kehilangan seluruh akses ke manajemen pengguna/peran-nya sendiri.
     */
    public const RESERVED = ['users', 'roles'];

    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function isValid(string $key): bool
    {
        return array_key_exists($key, self::ALL);
    }

    /**
     * Bentuk {key, label}[] yang dikonsumsi RoleController::menuKeys() dan
     * RoleMenuPicker.vue di frontend.
     */
    public static function list(): array
    {
        return array_map(
            fn (string $key, string $label) => ['key' => $key, 'label' => $label],
            array_keys(self::ALL),
            array_values(self::ALL),
        );
    }
}
