<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id', 'line_type', 'material_id', 'product_id',
        'description', 'qty', 'unit_price', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function bomLines(): HasMany
    {
        return $this->hasMany(ProductVariantBomLine::class);
    }

    /**
     * 034-seller-po-bom — SATU-SATUNYA definisi "baris PO yang boleh jadi
     * sumber BOM": PO milik seller itu dan berstatus ordered/received/paid.
     * Draft dikecualikan karena barisnya dihapus-dan-dibuat-ulang setiap
     * edit (rujukan tidak stabil); cancelled bukan pembelian sungguhan.
     * Lewat whereHas pada PurchaseOrder supaya global scope DEMO/LIVE
     * (HasDataMode) ikut berlaku — PurchaseOrderItem sendiri tidak
     * memakainya.
     */
    public function scopeEligibleForSeller(Builder $query, int $artistId): Builder
    {
        return $query->whereHas('purchaseOrder', fn ($po) => $po
            ->where('artist_id', $artistId)
            ->whereIn('status', ['ordered', 'received', 'paid']));
    }
}
