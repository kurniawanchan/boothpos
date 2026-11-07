<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 093 (FR-020) — penjualan LANGSUNG sebuah produk bahan ditolak karena sisanya sudah dijanjikan (ditahan) oleh resep tab
 * terbuka. Selalu 409 `insufficient_stock_reserved`. Sengaja berbeda dari kekurangan stok murni (ValidationException lama
 * dari StockService) agar klien lama tidak berubah dan kasir tahu BAHWA stoknya ada tetapi sedang dijanjikan.
 */
class StockReservedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $variantId,
        public readonly string $sku,
        public readonly int $available,
        public readonly int $reserved,
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => 'insufficient_stock_reserved',
            'variant_id' => $this->variantId,
            'sku' => $this->sku,
            'available' => $this->available,
            'reserved' => $this->reserved,
        ];
    }
}
