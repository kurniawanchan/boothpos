<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 045 Stage 5 — saldo stok varian di satu outlet. Hanya StockService yang menulis (di transaksi yang sama dengan
 * total tersimpan `product_variants.current_stock` dan baris `stock_movements`).
 */
class VariantOutletStock extends Model
{
    protected $table = 'variant_outlet_stock';

    protected $fillable = ['variant_id', 'outlet_id', 'current_stock'];

    protected function casts(): array
    {
        return ['current_stock' => 'integer'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
