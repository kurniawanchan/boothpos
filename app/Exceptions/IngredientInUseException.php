<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 093 (FR-011; research D8) — produk/varian yang dirujuk resep tidak-arsip tidak boleh dinonaktifkan, dihapus, dipindah ke
 * sumber stok `modifier`, atau dimatikan pelacakan stoknya: pemakaian resepnya akan berhenti memotong stok tanpa suara.
 * Selalu 409 `ingredient_in_use` dengan daftar resep yang memakainya.
 */
class IngredientInUseException extends RuntimeException
{
    /** @param list<array{id: int, name: string, product_name: string|null}> $recipes */
    public function __construct(string $message, public readonly array $recipes, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['message' => $this->getMessage(), 'code' => 'ingredient_in_use', 'recipes' => $this->recipes];
    }
}
