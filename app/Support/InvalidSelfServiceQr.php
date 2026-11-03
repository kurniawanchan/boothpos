<?php

namespace App\Support;

use RuntimeException;

/**
 * 090 — token QR meja ditolak. Dua sebab saja yang boleh terlihat dari luar (research D6, kontrak):
 *  - `qr_invalid` (404): format/tanda tangan salah, id tidak cocok, atau nonce usang (QR sudah dicabut). Tidak
 *    dibedakan lebih jauh supaya token tidak bisa diselidiki.
 *  - `table_unavailable` (409): tanda tangan SAH tetapi meja/outlet nonaktif atau terhapus, atau mode DEMO/LIVE
 *    berbeda. Pemegang token sah memang berhak diberi tahu (spec US2 skenario 4); orang tanpa token sah tidak.
 */
final class InvalidSelfServiceQr extends RuntimeException
{
    public const QR_INVALID = 'qr_invalid';

    public const TABLE_UNAVAILABLE = 'table_unavailable';

    public function __construct(public readonly string $reason = self::QR_INVALID)
    {
        parent::__construct(__('self_service.'.$reason));
    }

    public function status(): int
    {
        return $this->reason === self::TABLE_UNAVAILABLE ? 409 : 404;
    }
}
