<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 055 — simpanan referensi transaksi akan melewati batas gambar per penjualan. 409 (bukan 422) karena
 * bergantung pada gambar yang SUDAH tersimpan saat itu, bukan pada bentuk permintaan; `remaining` memberi
 * tahu UI berapa gambar lagi yang masih muat tanpa mengurai kalimat galat.
 */
class OrderReferenceLimitException extends RuntimeException
{
    public const CODE = 'reference_image_limit';

    public function __construct(string $message, public readonly int $max, public readonly int $remaining)
    {
        parent::__construct($message);
    }
}
