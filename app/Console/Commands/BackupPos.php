<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupException;
use App\Services\BackupService;
use Illuminate\Console\Command;

/**
 * php artisan app:backup
 *
 * Mendump MySQL dan mengarsipkan bukti pembayaran, lalu menyalin ke lokasi
 * eksternal (flashdisk/HDD) yang path-nya dikonfigurasi via env
 * BACKUP_EXTERNAL_PATH. Seluruh logikanya ada di BackupService — dipakai
 * bersama antarmuka Pengaturan (BackupController), jadi cadangan dari CLI dan
 * dari layar identik. Bug historis mysqldump/proc_open (environment child,
 * path disk 'local') dan perbaikannya kini ada di MysqlCliDumper dan
 * BackupService.
 *
 * Dijadwalkan harian lewat routes/console.php (Schedule::command), dan
 * dapat dipicu manual sebelum berangkat ke event.
 */
class BackupPos extends Command
{
    protected $signature = 'app:backup';
    protected $description = 'Cadangkan database dan berkas bukti pembayaran ke penyimpanan lokal dan eksternal';

    public function handle(BackupService $backups): int
    {
        $this->info('Membuat cadangan database...');

        try {
            $backup = $backups->create();
        } catch (BackupException $e) {
            $this->error('Cadangan gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($backup['external_copied'] === true) {
            $this->info('Menyalin ke penyimpanan eksternal: '.config('backup.external_path'));
        } else {
            $this->warn('BACKUP_EXTERNAL_PATH tidak diset atau tidak ditemukan — cadangan HANYA ada di laptop ini. Ini bukan cadangan yang aman untuk risiko kerusakan perangkat.');
        }

        $this->info('Selesai: '.$backups->directory().'/'.$backup['id']);

        return self::SUCCESS;
    }
}
