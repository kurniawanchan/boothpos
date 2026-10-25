<?php

namespace Database\Seeders;

/**
 * 069 — katalog Warkop WJM 2 (ekspor Odoo) ke mode LIVE dan DEMO.
 *
 *   php artisan db:seed --class=Wjm2CatalogSeeder       (APP_ENV=production: tambahkan --force milik db:seed)
 *
 * Perilakunya sama dengan WjmCatalogSeeder; yang berbeda hanya berkas katalognya. Kebijakan per outlet (outlet
 * tujuan "Warkop WJM 2", stasiun, penarikan item lain dari menu) dibawa katalog itu sendiri, bukan kode ini.
 * Sama seperti katalog WJM 3, seeder ini SENGAJA tidak dipanggil DatabaseSeeder: katalog ini milik satu pelanggan.
 * Pratinjau dan pilihan mode/outlet ada di `php artisan app:seed-wjm-catalog --catalog=...`.
 */
class Wjm2CatalogSeeder extends WjmCatalogSeeder
{
    protected function catalogPath(): string
    {
        return config('seeding.wjm2_catalog_path', database_path('seeders/data/wjm2-odoo/catalog.json'));
    }
}
