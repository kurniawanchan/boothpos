<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 048 / S2 — pelanggaran aturan shift (kas keluar di atas ambang, selisih tutup di atas toleransi,
 * QRIS tertunda, shift bukan milik pengguna) yang membawa KODE mesin-terbaca. Sama seperti
 * {@see BomRuleException}: 409 kecuali ditentukan lain, dan controller memetakan `reason` ke `code`
 * respons supaya aplikasi Android bisa menampilkan tindakan yang tepat tanpa mengurai kalimat.
 */
class ShiftRuleException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context  data tambahan untuk respons (mis. selisih, toleransi)
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly array $context = [],
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }
}
