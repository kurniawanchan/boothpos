<?php

namespace Tests\Support;

use App\Services\Backup\BackupException;
use App\Services\Backup\DatabaseDumper;

/**
 * Pengganti MysqlCliDumper untuk test: mencatat urutan panggilan dan isi berkas
 * yang dimuat, TANPA menjalankan mysqldump/mysql (tidak ada di image dev, dan
 * restore sungguhan akan menimpa database).
 */
class FakeDatabaseDumper implements DatabaseDumper
{
    public const VALID_DUMP = "-- MySQL dump (fake)\nCREATE TABLE `settings` (`id` int);\nCREATE TABLE `users` (`id` int);\n";

    /** @var list<array{0:string,1:string}> ['dump'|'restore', path] dalam urutan panggilan */
    public array $calls = [];

    public string $dumpContent = self::VALID_DUMP;

    public ?string $restoredContent = null;

    public bool $failDump = false;

    public bool $failRestore = false;

    /** Gagalkan dump hanya mulai panggilan ke-N (1 = yang pertama) — untuk cadangan pengaman. */
    public ?int $failDumpFromCall = null;

    private int $dumpCount = 0;

    public function dump(string $toFile): void
    {
        $this->calls[] = ['dump', $toFile];
        $this->dumpCount++;

        if ($this->failDump || ($this->failDumpFromCall !== null && $this->dumpCount >= $this->failDumpFromCall)) {
            throw new BackupException('mysqldump gagal (fake)');
        }

        file_put_contents($toFile, $this->dumpContent);
    }

    public function restore(string $fromFile): void
    {
        $this->calls[] = ['restore', $fromFile];

        if ($this->failRestore) {
            throw new BackupException('pemulihan gagal (fake)');
        }

        $this->restoredContent = file_get_contents($fromFile);
    }

    /** @return list<string> */
    public function callTypes(): array
    {
        return array_map(fn (array $c) => $c[0], $this->calls);
    }
}
