<?php

namespace App\Exceptions;

use RuntimeException;

/** 048 / B1 — PIN ditolak: salah (401), terkunci (429), atau tak bisa dipakai. `$code` bersifat mesin-terbaca. */
class PinRejectedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }
}
