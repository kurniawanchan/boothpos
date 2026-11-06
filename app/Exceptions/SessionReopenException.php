<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 063 — pembukaan-kembali shift ditolak aturan bisnis dengan KODE mesin-terbaca
 * (`session_not_closed`, `cashier_has_open_session`). Selalu 409 (konvensi proyek untuk konflik).
 */
class SessionReopenException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
