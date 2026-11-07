<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 092 FR-013 (research D5) — pesanan ditolak karena bahan resep tidak cukup. Selalu 409 `insufficient_recipe_stock`
 * (konvensi proyek untuk konflik aturan bisnis), dirender di bootstrap/app.php.
 *
 * Membawa bahan penghambat supaya kasir tahu APA yang kurang, bukan hanya "stok tidak cukup". Respons PUBLIK pesan
 * mandiri (090) membuang nama dan id bahan: itu detail internal toko, bukan untuk pelanggan.
 */
class RecipeStockException extends RuntimeException
{
    /**
     * @param  array{material_id: int, name: string, unit: string, on_hand: string, required: string}  $limiting
     */
    public function __construct(
        string $message,
        public readonly array $limiting,
        public readonly int $availablePortions,
        public readonly string $menu,
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }

    /** Bentuk respons internal (kasir/web/tablet), contracts/api-changes.md §2. */
    public function toInternalArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => 'insufficient_recipe_stock',
            'limiting' => $this->limiting + ['available_portions' => $this->availablePortions],
        ];
    }

    /** Bentuk respons publik pesan mandiri: tanpa nama/id bahan. */
    public function toPublicArray(): array
    {
        return [
            'message' => __('orders_payments.insufficient_recipe_stock_public', ['menu' => $this->menu, 'portions' => $this->availablePortions]),
            'code' => 'insufficient_recipe_stock',
            'limiting' => ['available_portions' => $this->availablePortions],
        ];
    }
}
