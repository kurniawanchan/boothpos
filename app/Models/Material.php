<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Material extends Model
{
    use HasDataMode, HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'unit',
        'kind',
        'standard_cost',
        'notes',
        'is_active',
        'current_stock',
    ];

    protected static function booted(): void
    {
        // 045 Stage 5 — stok awal/penulisan langsung dibukukan ke saldo outlet (invarian I2).
        static::created(fn (Material $m) => \App\Support\OutletStockSync::materialChanged($m, (float) $m->current_stock));
        static::updated(function (Material $m) {
            if ($m->wasChanged('current_stock')) {
                \App\Support\OutletStockSync::materialChanged($m, (float) $m->current_stock - (float) $m->getOriginal('current_stock'));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'current_stock' => 'decimal:3',
        ];
    }

    protected function code(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            set: fn (string $value) => strtoupper($value),
        );
    }

    public function vendorPrices(): HasMany
    {
        return $this->hasMany(VendorMaterialPrice::class);
    }

    public function bomLines(): HasMany
    {
        return $this->hasMany(ProductVariantBomLine::class);
    }

    public function outletStock(): HasMany
    {
        return $this->hasMany(MaterialOutletStock::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(MaterialStockMovement::class);
    }

    /** 087 — pilihan modifier yang memakai bahan ini ("dipakai oleh"). */
    public function modifierUsages(): HasMany
    {
        return $this->hasMany(ModifierOptionMaterial::class);
    }

    /**
     * Harga acuan bahan ini untuk perhitungan biaya BOM — lihat dokblok
     * BomCostCalculator untuk aturan lengkap pemilihan vendor saat lebih
     * dari satu vendor menjual bahan yang sama.
     */
    public function referencePrice(): ?VendorMaterialPrice
    {
        return $this->vendorPrices()
            ->with('vendor')
            ->orderByDesc('is_preferred')
            ->orderBy('price')
            ->orderBy('id')
            ->first();
    }
}
