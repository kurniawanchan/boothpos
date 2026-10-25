<?php

namespace App\Console\Commands;

use App\Services\WjmCatalogSeedService;
use App\Support\WjmCatalog;
use App\Support\WjmCatalogSeedReport;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * 068 — mengisi katalog Warkop WJM (ekspor Odoo) ke mode LIVE dan/atau DEMO. Kontrak:
 * specs/068-odoo-catalog-seeder/contracts/cli.md. Logikanya di WjmCatalogSeedService; perintah ini hanya
 * memeriksa opsi, meminta konfirmasi di produksi, dan mencetak laporan.
 */
class SeedWjmCatalog extends Command
{
    use ConfirmableTrait;

    protected $signature = 'app:seed-wjm-catalog
        {--mode=both : live, demo, atau both (LIVE diproses lebih dulu)}
        {--outlet= : kode outlet tujuan; hanya bersama --mode=live atau --mode=demo}
        {--dry-run : pratinjau: jalankan lalu batalkan, tanpa menyimpan data maupun foto}
        {--catalog= : berkas katalog (bawaan: database/seeders/data/wjm-odoo/catalog.json)}
        {--force : lewati konfirmasi saat APP_ENV=production}';

    protected $description = 'Isi katalog Warkop WJM (ekspor Odoo) ke mode LIVE dan/atau DEMO';

    public function handle(WjmCatalogSeedService $service): int
    {
        $mode = (string) $this->option('mode');
        $modes = match ($mode) {
            'both' => ['live', 'demo'],
            'live', 'demo' => [$mode],
            default => null,
        };
        if ($modes === null) {
            $this->error('--mode harus live, demo, atau both.');

            return self::FAILURE;
        }

        $outlet = $this->option('outlet') ?: null;
        if ($outlet !== null && count($modes) !== 1) {
            $this->error('--outlet hanya bisa dipakai bersama satu --mode (live atau demo), karena kode outlet unik lintas mode.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $path = $this->option('catalog') ?: config('seeding.wjm_catalog_path', database_path('seeders/data/wjm-odoo/catalog.json'));
        $catalog = WjmCatalog::fromFile($path);
        $this->line("Katalog: {$path}");

        // Konfirmasi hanya bila pengisian sungguhan memang akan berjalan: katalog rusak atau draf ditolak service
        // tanpa perlu bertanya dulu, dan pratinjau tidak menyimpan apa pun.
        $willWrite = ! $dryRun && $catalog->problems() === [] && $catalog->isApproved();
        if ($willWrite && ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $reports = $service->run($catalog, $modes, $outlet, $dryRun);
        foreach ($reports as $report) {
            $this->newLine();
            $report->printTo($this);
        }

        $this->newLine();
        $this->line('Selesai: '.collect($reports)->map(fn (WjmCatalogSeedReport $r, string $m) => strtoupper($m).' '.$r->status)->implode(', ').'.');

        return collect($reports)->every(fn (WjmCatalogSeedReport $r) => $r->isSuccessful()) ? self::SUCCESS : self::FAILURE;
    }
}
