<?php

namespace App\Exceptions;

/**
 * Kegagalan pada SATU baris berkas impor yang baru ketahuan saat tahap
 * penerapan (bukan saat validasi) — misalnya siklus kategori yang baru
 * bisa diperiksa setelah induknya benar-benar tersimpan.
 *
 * Dilempar dari dalam transaksi supaya seluruh impor ikut rollback, lalu
 * ditangkap di luar transaksi dan dilaporkan dengan bentuk galat per-baris
 * yang sama seperti galat validasi — pengguna tidak perlu tahu galatnya
 * ketahuan di tahap mana.
 */
class MasterDataImportRowException extends \RuntimeException
{
    public function __construct(
        public readonly string $sheet,
        public readonly int $row,
        public readonly ?string $column,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function toArray(): array
    {
        return [
            'sheet' => $this->sheet,
            'row' => $this->row,
            'column' => $this->column,
            'message' => $this->getMessage(),
        ];
    }
}
