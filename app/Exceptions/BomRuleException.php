<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 034-seller-po-bom — pelanggaran aturan bisnis BOM (selesai/buka kembali/
 * kunci harga modal) yang punya KODE mesin-terbaca selain pesan: UI
 * memetakan `reason` ke tindakan yang tepat (mis. menampilkan baris legacy
 * yang menghalangi), tanpa mengurai kalimat galat. Selalu 409 kecuali
 * ditentukan lain — konvensi proyek untuk konflik aturan bisnis.
 */
class BomRuleException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context  data tambahan untuk respons (mis. 'rows')
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
