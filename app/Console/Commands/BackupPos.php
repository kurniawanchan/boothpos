<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * php artisan app:backup
 *
 * Mendump MySQL via mysqldump dan mengarsipkan storage/app/payment-proofs,
 * lalu menyalin ke lokasi eksternal (flashdisk/HDD) yang path-nya
 * dikonfigurasi via env BACKUP_EXTERNAL_PATH.
 *
 * Dijadwalkan harian lewat routes/console.php (Schedule::command), dan
 * dapat dipicu manual sebelum berangkat ke event.
 *
 * DIVERIFIKASI JALAN — dieksekusi sungguhan terhadap database dev lokal
 * (lihat README bagian "Cadangan & pemulihan (WBS 9.2)" untuk hasil dan
 * prosedur pemulihan lengkap).
 */
class BackupPos extends Command
{
    protected $signature = 'app:backup';
    protected $description = 'Cadangkan database dan berkas bukti pembayaran ke penyimpanan lokal dan eksternal';

    public function handle(): int
    {
        $timestamp = now()->format('Y-m-d_His');
        $backupDir = storage_path("app/backups/{$timestamp}");

        if (! is_dir($backupDir) && ! mkdir($backupDir, 0755, true) && ! is_dir($backupDir)) {
            $this->error("Gagal membuat direktori backup: {$backupDir}");
            return self::FAILURE;
        }

        $dbHost = config('database.connections.mysql.host');
        $dbName = config('database.connections.mysql.database');
        $dbUser = config('database.connections.mysql.username');
        $dbPass = config('database.connections.mysql.password');

        $sqlFile = "{$backupDir}/database.sql";

        // Password dilewatkan via MYSQL_PWD environment variable pada
        // proses child, BUKAN sebagai argumen command line — argumen CLI
        // terlihat oleh proses lain lewat `ps aux` di sistem multi-user;
        // MYSQL_PWD tidak.
        $command = sprintf(
            'mysqldump --host=%s --user=%s %s > %s',
            escapeshellarg($dbHost),
            escapeshellarg($dbUser),
            escapeshellarg($dbName),
            escapeshellarg($sqlFile)
        );

        // BUG YANG DITEMUKAN & DIPERBAIKI — proc_open() mengganti SELURUH
        // environment proses child dengan array yang diberikan di argumen
        // ke-5, bukan MENAMBAHKAN ke environment yang sudah ada (beda dari
        // exec()/shell_exec() yang otomatis mewarisi environment penuh).
        // Sebelumnya di sini hanya ['MYSQL_PWD' => $dbPass] yang dilewatkan,
        // sehingga child process TIDAK PUNYA PATH sama sekali dan gagal
        // dengan "mysqldump: command not found" (exit 127) — walau
        // mysqldump terpasang benar di PATH proses PHP-nya sendiri.
        // Baru ketahuan setelah benar-benar dijalankan, persis seperti yang
        // diperingatkan komentar lama di sini soal "belum pernah dites".
        $env = getenv() + ['MYSQL_PWD' => $dbPass];

        $this->info('Membuat dump database...');
        $process = proc_open($command, [2 => ['pipe', 'w']], $pipes, null, $env);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || ! file_exists($sqlFile) || filesize($sqlFile) === 0) {
            $this->error('mysqldump gagal atau menghasilkan berkas kosong.');
            if ($stderr !== '') {
                $this->error($stderr);
            }
            return self::FAILURE;
        }

        $this->info('Mengarsipkan bukti pembayaran...');
        // BUG YANG DITEMUKAN & DIPERBAIKI saat bootstrap Laravel 13: sejak
        // Laravel 11, root disk 'local' default adalah storage/app/private,
        // BUKAN storage/app langsung. PaymentProofController menyimpan
        // lewat Storage::disk('local'), jadi path fisiknya sekarang
        // storage/app/private/payment-proofs. Path lama (storage_path
        // ('app/payment-proofs')) tidak akan pernah ada, sehingga
        // is_dir() di bawah selalu false dan cadangan diam-diam
        // melewati SELURUH bukti pembayaran tanpa galat apa pun.
        // Diselesaikan dengan meminta path dari disk itu sendiri, bukan
        // menghardcode asumsi struktur direktori Laravel versi tertentu.
        $proofsPath = Storage::disk('local')->path('payment-proofs');
        if (is_dir($proofsPath)) {
            $tarFile = "{$backupDir}/payment-proofs.tar.gz";
            exec(sprintf('tar -czf %s -C %s .', escapeshellarg($tarFile), escapeshellarg($proofsPath)));
        }

        $externalPath = config('backup.external_path'); // set via env BACKUP_EXTERNAL_PATH
        if ($externalPath && is_dir($externalPath)) {
            $this->info("Menyalin ke penyimpanan eksternal: {$externalPath}");
            exec(sprintf('cp -r %s %s', escapeshellarg($backupDir), escapeshellarg($externalPath)));
        } else {
            $this->warn('BACKUP_EXTERNAL_PATH tidak diset atau tidak ditemukan — cadangan HANYA ada di laptop ini. Ini bukan cadangan yang aman untuk risiko kerusakan perangkat.');
        }

        $this->info("Selesai: {$backupDir}");
        return self::SUCCESS;
    }
}
