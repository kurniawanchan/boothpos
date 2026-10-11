<?php

namespace App\Services\DemoClone;

use Illuminate\Database\QueryException;

/**
 * Pesan kegagalan yang AMAN dicetak: tanpa nilai baris. Pesan QueryException
 * dan PDOException memuat nilai binding / "Duplicate entry '<nilai>'" —
 * untuk tabel customers itu PII (telepon, email). Yang boleh keluar hanya:
 * pesan CloneException (hanya nama tabel/kolom/id), SQLSTATE + kode driver +
 * SQL bertanda tanya (tanpa nilai), atau nama kelas.
 */
final class SafeError
{
    public static function describe(\Throwable $e): string
    {
        if ($e instanceof CloneException) {
            return $e->getMessage();
        }

        if ($e instanceof QueryException) {
            $state = $e->errorInfo[0] ?? 'tidak diketahui';
            $driver = $e->errorInfo[1] ?? '?';

            return "Kesalahan database (SQLSTATE {$state}, kode {$driver}) pada: ".$e->getSql();
        }

        return get_class($e).' (pesan disembunyikan karena dapat memuat data).';
    }
}
