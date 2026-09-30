<?php

namespace App\Services;

use App\Services\Backup\BackupException;
use App\Services\Backup\DatabaseDumper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Cadangan & pemulihan database — dipakai bersama oleh perintah artisan
 * (`app:backup`, `app:restore`) dan antarmuka Pengaturan (BackupController),
 * supaya keduanya menghasilkan cadangan yang persis sama.
 *
 * Satu cadangan = satu subfolder di config('backup.path'), bernama
 * `Y-m-d_His` (+ `-N` bila ada dua dalam detik yang sama), berisi
 * `database.sql` dan — bila ada — `payment-proofs.tar.gz`. Nama folder itu
 * adalah satu-satunya id yang diterima dari luar, dan dicek dengan ID_PATTERN
 * sebelum menyentuh sistem berkas (mencegah path traversal).
 *
 * PEMULIHAN SELALU didahului cadangan pengaman: restore menimpa seluruh
 * database dan tidak bisa dibatalkan, jadi keadaan sebelumnya disimpan dulu.
 * Bila cadangan pengaman gagal, pemulihan TIDAK dijalankan.
 *
 * Berkas bukti pembayaran hanya DICADANGKAN, tidak dipulihkan — sama seperti
 * `app:restore` sejak awal (ekstrak manual `payment-proofs.tar.gz`).
 */
class BackupService
{
    /** Pola id cadangan (untuk validasi di rute dan di sini). */
    public const ID_PATTERN = '\d{4}-\d{2}-\d{2}_\d{6}(-\d+)?';

    public function __construct(private DatabaseDumper $dumper) {}

    public function directory(): string
    {
        return rtrim((string) config('backup.path'), '/');
    }

    /**
     * Buat cadangan baru. Bila gagal di tengah jalan, folder setengah jadi
     * dihapus (tidak ada cadangan yang tampak ada tapi rusak).
     *
     * @return array{id:string,created_at:string,size_bytes:int,has_payment_proofs:bool,external_copied:?bool}
     *
     * @throws BackupException
     */
    public function create(): array
    {
        $id = $this->newId();
        $dir = "{$this->directory()}/{$id}";

        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new BackupException("Gagal membuat direktori cadangan: {$dir}");
        }

        try {
            $sqlFile = "{$dir}/database.sql";
            $this->dumper->dump($sqlFile);

            if (! is_file($sqlFile) || filesize($sqlFile) === 0) {
                throw new BackupException('Dump database kosong.');
            }

            $this->archivePaymentProofs($dir);
            $externalCopied = $this->copyToExternal($dir);
        } catch (\Throwable $e) {
            File::deleteDirectory($dir);

            throw $e instanceof BackupException ? $e : new BackupException($e->getMessage(), 0, $e);
        }

        return $this->describe($id) + ['external_copied' => $externalCopied];
    }

    /**
     * Semua cadangan yang utuh (punya database.sql), terbaru dulu.
     *
     * @return list<array{id:string,created_at:string,size_bytes:int,has_payment_proofs:bool}>
     */
    public function list(): array
    {
        $ids = [];
        foreach (glob("{$this->directory()}/*", GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            if ($this->sqlPath($id) !== null) {
                $ids[] = $id;
            }
        }
        rsort($ids, SORT_STRING); // `Y-m-d_His` terurut kronologis secara leksikal

        return array_map(fn (string $id) => $this->describe($id), $ids);
    }

    /** Path database.sql cadangan ini, atau null bila id tak valid / tak ada. */
    public function sqlPath(string $id): ?string
    {
        if (preg_match('/^'.self::ID_PATTERN.'$/', $id) !== 1) {
            return null;
        }

        $path = "{$this->directory()}/{$id}/database.sql";

        return is_file($path) ? $path : null;
    }

    /**
     * Hapus SATU cadangan beserta seluruh isinya (database.sql dan arsip bukti
     * pembayarannya). Id divalidasi lewat sqlPath() — pola id DAN keberadaan —
     * sebelum menyentuh sistem berkas, jadi id sembarang tidak bisa menunjuk
     * folder lain. Hanya salinan lokal yang dihapus: salinan di
     * BACKUP_EXTERNAL_PATH (bila ada) dan bukti pembayaran ASLI tidak tersentuh.
     *
     * @return bool false bila id tidak valid / cadangan tidak ada
     */
    public function delete(string $id, ?int $userId = null): bool
    {
        if ($this->sqlPath($id) === null) {
            return false;
        }

        File::deleteDirectory("{$this->directory()}/{$id}");

        Log::warning("Database backup {$id} deleted", ['user_id' => $userId, 'backup' => $id]);

        return true;
    }

    /**
     * Timpa database dengan berkas dump — setelah membuat cadangan pengaman.
     *
     * @return array{source:string,safety_backup:array}
     *
     * @throws BackupException bila cadangan pengaman atau pemulihan gagal
     */
    public function restoreFromFile(string $sqlPath, string $source, ?int $userId = null): array
    {
        try {
            $safety = $this->create();
        } catch (BackupException $e) {
            // Tanpa cadangan pengaman, pemulihan TIDAK dijalankan — dan pengguna
            // harus tahu itu keputusan sengaja, bukan sekadar galat mentah.
            throw new BackupException(__('backups.safety_backup_failed', ['reason' => $e->getMessage()]), 0, $e);
        }

        Log::warning("Database restore from {$source} started", [
            'user_id' => $userId,
            'source' => $source,
            'safety_backup' => $safety['id'],
        ]);

        $this->dumper->restore($sqlPath);

        return ['source' => $source, 'safety_backup' => $safety];
    }

    /**
     * Pemeriksaan kewajaran untuk berkas unggahan: harus tampak seperti dump
     * database aplikasi ini (memuat tabel `settings` dan `users`). Mencegah
     * dump database lain — atau berkas sembarang — menimpa data toko.
     */
    public function looksLikeAppDump(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $found = ['settings' => false, 'users' => false];
        while (($line = fgets($handle)) !== false) {
            if (preg_match('/^CREATE TABLE (IF NOT EXISTS )?`(settings|users)`/i', $line, $m) === 1) {
                $found[strtolower($m[2])] = true;
                if (! in_array(false, $found, true)) {
                    break;
                }
            }
        }
        fclose($handle);

        return ! in_array(false, $found, true);
    }

    private function newId(): string
    {
        $base = Carbon::now()->format('Y-m-d_His');
        $id = $base;

        for ($n = 2; is_dir("{$this->directory()}/{$id}"); $n++) {
            $id = "{$base}-{$n}";
        }

        return $id;
    }

    /** @return array{id:string,created_at:string,size_bytes:int,has_payment_proofs:bool} */
    private function describe(string $id): array
    {
        $dir = "{$this->directory()}/{$id}";
        $sql = "{$dir}/database.sql";

        return [
            'id' => $id,
            'created_at' => Carbon::createFromTimestamp((int) filemtime($sql))->toIso8601String(),
            'size_bytes' => (int) filesize($sql),
            'has_payment_proofs' => is_file("{$dir}/payment-proofs.tar.gz"),
        ];
    }

    /**
     * Path fisik diminta dari disk itu sendiri (bukan di-hardcode) — sejak
     * Laravel 11 root disk 'local' adalah storage/app/private, lihat catatan
     * bug di riwayat BackupPos.
     */
    private function archivePaymentProofs(string $backupDir): void
    {
        $proofsPath = Storage::disk('local')->path('payment-proofs');
        if (is_dir($proofsPath)) {
            $tarFile = "{$backupDir}/payment-proofs.tar.gz";
            exec(sprintf('tar -czf %s -C %s .', escapeshellarg($tarFile), escapeshellarg($proofsPath)));
        }
    }

    /** true = disalin, false = path diset tapi tak ditemukan/gagal, null = tidak dikonfigurasi. */
    private function copyToExternal(string $backupDir): ?bool
    {
        $externalPath = config('backup.external_path');
        if (! $externalPath) {
            return null;
        }
        if (! is_dir($externalPath)) {
            return false;
        }

        exec(sprintf('cp -r %s %s', escapeshellarg($backupDir), escapeshellarg($externalPath)), $output, $code);

        return $code === 0;
    }
}
