<?php

namespace App\Exceptions;

/**
 * 028 — konflik aturan bisnis pada tab yang perlu membawa data tambahan ke
 * respons 409 (mis. `existing_tab_id` saat meja sudah dipakai tab lain).
 * Konflik tanpa data tambahan cukup memakai ValidationException seperti
 * kasir ritel (OrderController memetakannya ke 409).
 */
class TabConflictException extends \RuntimeException
{
    /** @param array<string, mixed> $extra kunci tambahan untuk badan respons */
    public function __construct(string $message, public readonly array $extra = [])
    {
        parent::__construct($message);
    }
}
