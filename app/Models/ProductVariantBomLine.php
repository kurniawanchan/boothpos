<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris Bill of Materials: "varian X butuh Y unit bahan Z per unit
 * produk jadi". Diikat ke ProductVariant, bukan Product — lihat catatan
 * desain di migration create_vendors_and_materials_tables.
 */
class ProductVariantBomLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_variant_id',
        'material_id',
        'qty_needed',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'qty_needed' => 'decimal:4',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
