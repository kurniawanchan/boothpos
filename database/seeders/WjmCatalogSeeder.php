<?php

namespace Database\Seeders;

use App\Services\WjmCatalogSeedService;
use App\Support\WjmCatalog;
use Illuminate\Database\Seeder;

/**
 * 068 — katalog Warkop WJM (ekspor Odoo) ke mode LIVE dan DEMO.
 *
 *   php artisan db:seed --class=WjmCatalogSeeder        (APP_ENV=production: tambahkan --force milik db:seed)
 *
 * SENGAJA tidak dipanggil DatabaseSeeder (FR-001): katalog ini milik satu pelanggan dan tidak boleh ikut
 * penyiapan instalasi lain. Pratinjau, pilihan mode, dan pilihan outlet ada di
 * `php artisan app:seed-wjm-catalog`; keduanya memakai WjmCatalogSeedService yang sama. Sumber datanya
 * database/seeders/data/wjm-odoo/catalog.json, dibangkitkan dari catalog-mapping.md yang disetujui pemilik.
 */
class WjmCatalogSeeder extends Seeder
{
    /** Berkas katalog yang diisi seeder ini; Wjm2CatalogSeeder (069) hanya mengganti ini. */
    protected function catalogPath(): string
    {
        return config('seeding.wjm_catalog_path', database_path('seeders/data/wjm-odoo/catalog.json'));
    }

    public function run(WjmCatalogSeedService $service): void
    {
        $catalog = WjmCatalog::fromFile($this->catalogPath());
        $reports = $service->run($catalog, ['live', 'demo']);

        foreach ($reports as $report) {
            $report->printTo($this->command);
        }

        $failed = array_keys(array_filter($reports, fn ($report) => ! $report->isSuccessful()));
        if ($failed !== []) {
            // Dilempar supaya `db:seed` keluar dengan kode ≠ 0 dan skrip penyiapan berhenti.
            throw new \RuntimeException('Katalog WJM tidak terisi untuk mode: '.implode(', ', $failed).'. Lihat laporan di atas.');
        }
    }
}
