<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 059 — pelanggaran aturan persetujuan seller yang punya KODE mesin-terbaca (`already_approved`,
 * `approval_stale`, `order_voided`, `approval_not_allowed`): UI memetakan kode ke tindakan yang tepat
 * (muat ulang, sembunyikan tombol) tanpa mengurai kalimat galat. 409 untuk konflik aturan bisnis, 403
 * untuk penolakan hak — konvensi proyek.
 */
class OrderApprovalException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason, public readonly int $status = 409)
    {
        parent::__construct($message);
    }
}
