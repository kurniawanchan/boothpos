<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupException;
use App\Services\Backup\DatabaseDumper;
use Illuminate\Console\Command;

/**
 * php artisan app:restore {path}
 *
 * Memulihkan database dari berkas `database.sql` hasil `php artisan
 * app:backup`. Sengaja MEMANGGIL DatabaseDumper langsung (bukan
 * BackupService::restoreFromFile): perintah ini dipakai operator untuk
 * pemulihan bencana, dan tidak boleh terhalang cadangan pengaman yang bisa
 * gagal justru ketika database yang rusak itulah yang hendak diperbaiki.
 * Antarmuka Pengaturan memakai jalur berpengaman (lihat BackupService).
 */
class RestorePos extends Command
{
    protected $signature = 'app:restore
        {path : Path ke berkas database.sql hasil app:backup}
        {--force : Lewati konfirmasi interaktif (dipakai untuk automasi)}';

    protected $description = 'Pulihkan database dari berkas dump SQL hasil app:backup. MENIMPA seluruh data pada database tujuan saat ini.';

    public function handle(DatabaseDumper $dumper): int
    {
        $path = $this->argument('path');

        if (! file_exists($path)) {
            $this->error("Berkas tidak ditemukan: {$path}");
            return self::FAILURE;
        }

        $dbName = config('database.connections.mysql.database');

        if (! $this->option('force') && ! $this->confirm(
            "Ini akan MENIMPA SELURUH DATA di database '{$dbName}' dengan isi {$path}. Lanjutkan?"
        )) {
            $this->warn('Dibatalkan.');
            return self::FAILURE;
        }

        $this->info("Memulihkan database '{$dbName}' dari {$path}...");

        try {
            $dumper->restore($path);
        } catch (BackupException $e) {
            $this->error('Pemulihan gagal.');
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Database berhasil dipulihkan.');
        $this->warn('Berkas bukti pembayaran (payment-proofs.tar.gz) TIDAK dipulihkan otomatis oleh perintah ini — ekstrak manual ke storage/app/private/payment-proofs bila diperlukan.');

        return self::SUCCESS;
    }
}
