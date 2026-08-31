<?php

namespace Database\Seeders;

use App\Models\PaymentChannel;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * php artisan db:seed
 *
 * SENGAJA HANYA UNTUK LINGKUNGAN LOKAL/DEV. Kredensial di bawah ini
 * generik dan diketahui publik (ada di kode sumber) — JANGAN dipakai di
 * lingkungan manapun yang bisa diakses orang lain. Tidak ada mekanisme
 * "hanya jalan di local" otomatis di sini; itu tanggung jawab Anda saat
 * deploy nanti (misal dengan environment check sebelum registrasi
 * DatabaseSeeder di production).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedUsers();
        $this->seedSettings();
        $this->seedPaymentChannels();
    }

    private function seedUsers(): void
    {
        $accounts = [
            ['name' => 'Owner Dummy', 'username' => 'owner', 'role' => 'owner'],
            ['name' => 'Admin Dummy', 'username' => 'admin', 'role' => 'admin'],
            ['name' => 'Kasir Dummy', 'username' => 'kasir01', 'role' => 'cashier'],
            ['name' => 'Kasir Dummy Dua', 'username' => 'kasir02', 'role' => 'cashier'],
            ['name' => 'Inventory Dummy', 'username' => 'inventory', 'role' => 'inventory'],
        ];

        foreach ($accounts as $account) {
            User::updateOrCreate(
                ['username' => $account['username']],
                [
                    'name' => $account['name'],
                    'password' => Hash::make('password123'),
                    'role' => $account['role'],
                    'is_active' => true,
                ]
            );
        }

        $this->command->info('User dummy dibuat. Password untuk SEMUA akun: password123');
        $this->command->table(
            ['Username', 'Role'],
            collect($accounts)->map(fn ($a) => [$a['username'], $a['role']])->toArray()
        );
    }

    private function seedSettings(): void
    {
        $settings = [
            ['key' => 'store_name', 'value' => 'Toko Merchandise Dummy', 'type' => 'string', 'group' => 'receipt'],
            ['key' => 'store_contact', 'value' => '0812-0000-0000', 'type' => 'string', 'group' => 'receipt'],
            // Default TRUE di seeder dev ini supaya seluruh alur multi-artist
            // (yang sudah dibangun sejak awal proyek) tetap bisa dites end-to-end
            // tanpa hambatan. Instalasi produksi untuk pembeli Basic HARUS
            // di-set eksplisit ke false saat provisioning, bukan mengandalkan
            // default ini — lihat LicenseGate::multiArtistEnabled() yang
            // fallback ke false bila key ini tidak ada sama sekali.
            ['key' => 'multi_artist_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'licensing'],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(['key' => $setting['key']], $setting);
        }
    }

    private function seedPaymentChannels(): void
    {
        $channels = [
            ['type' => 'bank_transfer', 'provider' => 'BCA', 'account_name' => 'Toko Merchandise Dummy', 'account_number' => '1234567890', 'display_order' => 1],
            ['type' => 'bank_transfer', 'provider' => 'Mandiri', 'account_name' => 'Toko Merchandise Dummy', 'account_number' => '0987654321', 'display_order' => 2],
        ];

        foreach ($channels as $channel) {
            PaymentChannel::updateOrCreate(
                ['provider' => $channel['provider'], 'type' => $channel['type']],
                $channel
            );
        }
    }
}
