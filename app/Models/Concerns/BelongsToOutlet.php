<?php

namespace App\Models\Concerns;

use App\Support\OutletDefaults;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 045 Stage 2 — satu-satunya tempat `outlet_id` diisi untuk model operasional (Constitution I).
 *
 * - Baris baru dicap outlet saat dibuat, TIDAK PERNAH dari input: `outlet_id` sengaja tidak masuk
 *   $fillable model mana pun. Pengisi, berurutan: nilai yang sudah di-set pemanggil secara eksplisit
 *   (mis. shift yang outletnya sudah divalidasi) → outlet induk (`outletFromParent()`, mis. order
 *   mewarisi shift) → OutletDefaults::forNewRecord().
 * - Penyaringan query: OutletScope (global scope), dipasang otomatis.
 */
trait BelongsToOutlet
{
    protected static function bootBelongsToOutlet(): void
    {
        static::addGlobalScope(new OutletScope);

        static::creating(function (Model $model) {
            if ($model->getAttribute('outlet_id') === null) {
                $model->setAttribute('outlet_id', $model->outletFromParent() ?? OutletDefaults::forNewRecord());
            }
        });
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Outlet::class, 'outlet_id');
    }

    /** Outlet yang diwarisi dari induk; null = tidak ada induk, pakai OutletDefaults. Ditimpa per model. */
    protected function outletFromParent(): ?int
    {
        return null;
    }
}
