<?php

namespace Database\Seeders;

use App\Models\Outlet;
use App\Models\Role;
use App\Models\User;
use App\Support\ModeGate;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * 048 / T003 — peran dan staf contoh untuk outlet "Warkop WJM 3" (dipakai verifikasi aplikasi Android POS).
 *
 *   php artisan db:seed --class=WarkopPosStaffSeeder
 *
 * HANYA data demo/lokal: PIN di bawah adalah PIN contoh yang sengaja tidak rahasia. Idempoten — dijalankan ulang
 * tidak menggandakan peran/pengguna dan tidak menimpa PIN yang sudah diganti (hanya mengisi bila belum punya).
 * Sengaja TIDAK dipanggil dari DatabaseSeeder: instalasi sungguhan tidak boleh mendapat PIN contoh.
 */
class WarkopPosStaffSeeder extends Seeder
{
    /** [nama peran, menu_keys, izin tingkat-aksi atau null = bawaan nama peran] */
    private const ROLES = [
        'Kasir' => [['dashboard', 'pos', 'session', 'customers', 'sales', 'floor'], []],
        'Waiter' => [['dashboard', 'pos', 'customers', 'floor'], []],
        'Supervisor' => [['dashboard', 'pos', 'session', 'customers', 'sales', 'floor', 'reports'], [
            PermissionCatalog::ORDER_VOID, PermissionCatalog::ORDER_CANCEL, PermissionCatalog::CASH_OUT_APPROVE,
            PermissionCatalog::ORDER_REPRINT_APPROVE, PermissionCatalog::PAYMENT_QRIS_VERIFY,
            PermissionCatalog::MENU_AVAILABILITY_SET, PermissionCatalog::SHIFT_VARIANCE_APPROVE,
        ]],
        'Outlet Manager' => [['dashboard', 'pos', 'session', 'customers', 'sales', 'floor', 'reports', 'products', 'stock'], [
            PermissionCatalog::ORDER_VOID, PermissionCatalog::ORDER_CANCEL, PermissionCatalog::CASH_OUT_APPROVE,
            PermissionCatalog::ORDER_REPRINT_APPROVE, PermissionCatalog::PAYMENT_QRIS_VERIFY, PermissionCatalog::ORDER_REFUND,
            PermissionCatalog::MENU_AVAILABILITY_SET, PermissionCatalog::SHIFT_VARIANCE_APPROVE, PermissionCatalog::DISCOUNT_LIMIT_PCT,
        ]],
    ];

    /** username => [nama, peran, PIN contoh] */
    private const STAFF = [
        'rina' => ['Rina', 'Kasir', '482916'],
        'dewi' => ['Dewi', 'Kasir', '739204'],
        'andi' => ['Andi', 'Waiter', '615823'],
        'sari' => ['Sari', 'Supervisor', '294710'],
        'budi' => ['Budi', 'Outlet Manager', '837156'],
    ];

    public function run(): void
    {
        ModeGate::runAs('demo', function () {
            $outlet = Outlet::firstOrCreate(['code' => 'WJ3'], ['name' => 'Warkop WJM 3', 'is_active' => true]);

            $roles = [];
            foreach (self::ROLES as $name => [$menuKeys, $permissions]) {
                $roles[$name] = Role::firstOrCreate(['name' => $name], [
                    'menu_keys' => $menuKeys, 'permissions' => $permissions ?: null, 'is_system_default' => false, 'all_outlets' => false,
                ]);
            }

            // Peran dev yang sudah ada: perbarui izin dari konstanta di atas (firstOrCreate tidak mengubah baris lama).
            foreach (self::ROLES as $name => [, $permissions]) {
                if ($permissions !== []) {
                    $roles[$name]->forceFill(['permissions' => $permissions])->save();
                }
            }

            foreach (self::STAFF as $username => [$name, $role, $pin]) {
                $user = User::withoutGlobalScopes()->firstOrCreate(
                    ['username' => $username],
                    ['name' => $name, 'password' => Hash::make('password123'), 'role_id' => $roles[$role]->id, 'is_active' => true],
                );
                if ($user->pin_hash === null) {
                    $user->forceFill(['pin_hash' => Hash::make($pin), 'pin_changed_at' => now()])->save();
                }
                $user->outlets()->syncWithoutDetaching([$outlet->id]);
            }
        });
    }
}
