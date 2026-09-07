<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 019-billing-system — rename dari Package (research.md R1'): entitas
 * katalog lisensi/paket yang bisa dipilih saat onboarding company, kini
 * dengan harga (`price`) dan cara bayar (`payment_type`, label
 * deskriptif saja — FR-017, tidak ada billing berulang otomatis).
 * `license_tier` tetap murni deskriptif terhadap company (017 R4,
 * TIDAK PERNAH diterapkan otomatis ke Setting multi_artist_enabled
 * instalasi ini).
 */
class License extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'license_tier',
        'price',
        'payment_type',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'price' => 'decimal:2',
        ];
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
