<?php

namespace App\Services\Backup;

/**
 * DatabaseDumper nyata: membungkus `mysqldump` dan `mysql` (klien MySQL harus
 * terpasang — image toko membawanya, image dev tidak; lihat DatabaseDumper).
 *
 * Logika proses dipindahkan apa adanya dari BackupPos/RestorePos, termasuk dua
 * pelajaran dari bug nyata di sana:
 *  - Kata sandi lewat environment MYSQL_PWD milik proses anak, BUKAN argumen
 *    baris perintah (argumen terlihat oleh proses lain lewat `ps aux`).
 *  - proc_open() MENGGANTI seluruh environment anak bila diberi array, jadi
 *    environment induk digabung (bukan diganti) supaya PATH ikut terbawa —
 *    kalau tidak, `mysqldump: command not found` (exit 127).
 * Perbaikan kecil: array_merge (bukan operator +) — dengan `+`, MYSQL_PWD yang
 * kebetulan sudah ada di environment induk akan menimpa kata sandi konfigurasi.
 *
 * Nama biner dapat disuntikkan supaya test bisa memakai skrip pengganti.
 */
class MysqlCliDumper implements DatabaseDumper
{
    /** @param array{host?:string,username?:string,password?:?string,database?:string} $connection */
    public function __construct(
        private array $connection,
        private string $dumpBinary = 'mysqldump',
        private string $restoreBinary = 'mysql',
    ) {}

    public static function fromConfig(): self
    {
        return new self((array) config('database.connections.mysql'));
    }

    public function dump(string $toFile): void
    {
        $this->run(sprintf(
            '%s --host=%s --user=%s %s > %s',
            escapeshellarg($this->dumpBinary),
            escapeshellarg((string) ($this->connection['host'] ?? '')),
            escapeshellarg((string) ($this->connection['username'] ?? '')),
            escapeshellarg((string) ($this->connection['database'] ?? '')),
            escapeshellarg($toFile),
        ));

        if (! is_file($toFile) || filesize($toFile) === 0) {
            throw new BackupException('mysqldump menghasilkan berkas kosong.');
        }
    }

    public function restore(string $fromFile): void
    {
        if (! is_file($fromFile)) {
            throw new BackupException("Berkas tidak ditemukan: {$fromFile}");
        }

        // --binary-mode: berkas pulihan bisa DIUNGGAH (owner/admin), dan tanpa
        // opsi ini klien mysql tetap menjalankan perintah klien dari stdin
        // (`\! <perintah shell>`, `system`, `source`) — isi berkas bisa menjalankan
        // perintah OS sebagai pengguna PHP. Dengan opsi ini hanya `\C` dan
        // `DELIMITER` yang tersisa, dan dump mysqldump tetap terbaca utuh.
        $this->run(sprintf(
            '%s --binary-mode --host=%s --user=%s %s < %s',
            escapeshellarg($this->restoreBinary),
            escapeshellarg((string) ($this->connection['host'] ?? '')),
            escapeshellarg((string) ($this->connection['username'] ?? '')),
            escapeshellarg((string) ($this->connection['database'] ?? '')),
            escapeshellarg($fromFile),
        ));
    }

    private function run(string $command): void
    {
        $env = array_merge(getenv(), ['MYSQL_PWD' => (string) ($this->connection['password'] ?? '')]);

        $process = proc_open($command, [2 => ['pipe', 'w']], $pipes, null, $env);
        if (! is_resource($process)) {
            throw new BackupException('Gagal menjalankan proses cadangan/pemulihan.');
        }

        $stderr = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new BackupException($stderr !== '' ? $stderr : "Proses berhenti dengan kode {$exitCode}.");
        }
    }
}
