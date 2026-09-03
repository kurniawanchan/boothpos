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
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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
