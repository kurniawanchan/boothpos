<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 018-license-activation — paling banyak satu baris pernah ada
 * (instalasi single-tenant). Baris ini hanya ditulis lewat
 * LicenseActivationService::activate(), tidak punya permukaan API
 * create/update sendiri.
 */
class LicenseActivation extends Model
{
    protected $fillable = [
        'license_id', 'issued_to', 'machine_fingerprint_hash', 'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
        ];
    }
}
