<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 045 Stage 3 — harga varian khusus satu outlet (jarang). Tidak ada baris = mengikuti `sell_price` dasar.
 * HANYA ditulis PriceService (bersama baris price_history ber-outlet).
 */
class VariantOutletPrice extends Model
{
    use HasDataMode;

    protected $fillable = ['variant_id', 'outlet_id', 'price'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
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
