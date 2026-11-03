<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 054 (US7) — koreksi metode pembayaran ditolak oleh aturan bisnis yang punya KODE mesin-terbaca
 * (`closed_shift`, `change_given`, `voided`, `no_open_shift`): UI menampilkan penjelasan yang tepat
 * tanpa mengurai kalimat galat. Selalu 409 — konvensi proyek untuk konflik aturan bisnis.
 */
class PaymentMethodChangeException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason, public readonly int $status = 409)
    {
        parent::__construct($message);
    }
}
