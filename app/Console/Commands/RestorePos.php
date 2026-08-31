<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * php artisan app:restore {path}
 *
 * Memulihkan database dari berkas `database.sql` hasil `php artisan
 * app:backup`. Simetris dengan BackupPos, dan memakai pola environment
 * yang sama (lihat catatan bug di BackupPos::handle()) — proc_open()
 * diberi environment gabungan (getenv() + MYSQL_PWD), bukan environment
 * yang menggantikan seluruhnya, supaya PATH tetap terbawa ke proses anak.
 *
 * DIVERIFIKASI JALAN — lihat README bagian "Cadangan & pemulihan
 * (WBS 9.2)" untuk hasil uji pemulihan sungguhan.
 */
class RestorePos extends Command
{
    protected $signature = 'app:restore
        {path : Path ke berkas database.sql hasil app:backup}
        {--force : Lewati konfirmasi interaktif (dipakai untuk automasi)}';

    protected $description = 'Pulihkan database dari berkas dump SQL hasil app:backup. MENIMPA seluruh data pada database tujuan saat ini.';

    public function handle(): int
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

        $dbHost = config('database.connections.mysql.host');
        $dbUser = config('database.connections.mysql.username');
        $dbPass = config('database.connections.mysql.password');

        // Password lewat MYSQL_PWD, bukan argumen CLI — alasan sama seperti
        // BackupPos. Environment digabung (bukan diganti) supaya PATH tetap
        // terbawa; lihat komentar bug di BackupPos::handle().
        $command = sprintf(
            'mysql --host=%s --user=%s %s < %s',
            escapeshellarg($dbHost),
            escapeshellarg($dbUser),
            escapeshellarg($dbName),
            escapeshellarg($path)
        );
        $env = getenv() + ['MYSQL_PWD' => $dbPass];

        $this->info("Memulihkan database '{$dbName}' dari {$path}...");
        $process = proc_open($command, [2 => ['pipe', 'w']], $pipes, null, $env);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $this->error('Pemulihan gagal.');
            if ($stderr !== '') {
                $this->error($stderr);
            }
            return self::FAILURE;
        }

        $this->info('Database berhasil dipulihkan.');
        $this->warn('Berkas bukti pembayaran (payment-proofs.tar.gz) TIDAK dipulihkan otomatis oleh perintah ini — ekstrak manual ke storage/app/private/payment-proofs bila diperlukan.');

        return self::SUCCESS;
    }
}
