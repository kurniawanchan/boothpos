<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
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
    use HasDataMode, HasFactory;

    protected $fillable = [
        'product_variant_id',
        'material_id',
        'purchase_order_item_id',
        'line_type',
        'item_name',
        'po_number',
        'vendor_id',
        'vendor_name',
        'unit_cost',
        'qty_needed',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'qty_needed' => 'decimal:4',
            'unit_cost' => 'decimal:2',
        ];
    }

    /**
     * 034-seller-po-bom — baris tanpa baris purchase order adalah baris
     * LEGACY (dibuat sebelum fitur ini / lewat impor Excel): diturunkan,
     * bukan disimpan sebagai flag yang bisa melenceng dari kenyataan.
     */
    public function isLegacy(): bool
    {
        return $this->purchase_order_item_id === null;
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
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
