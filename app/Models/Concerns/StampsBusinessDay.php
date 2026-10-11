<?php

namespace App\Models\Concerns;

use App\Support\BusinessDay;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * 042-dashboard-revamp — memberi `business_date` (dan, untuk Order, `completed_at`) pada baris yang
 * dibuat/diselesaikan, DI MODEL, bukan di tiap service. Alasannya: ada banyak jalur yang membuat atau
 * menyelesaikan order (checkout POS, buka tab, pelunasan tab, pecah tab, impor, seeder); satu hook di
 * model tidak bisa terlewat oleh jalur ke-6 yang ditulis kelak (Konstitusi I: satu implementasi).
 *
 * Kolomnya TIDAK ada di `$fillable` mana pun, jadi nilai dari body request tidak pernah sampai ke
 * sini (Konstitusi IV: server yang menetapkan; klien tidak). Perubahan zona waktu/cut-off hanya
 * berlaku untuk baris yang distempel sesudahnya.
 *
 * Model pemakai boleh menimpa `businessInstant()` (dan Order menambah `stampCompletion()`).
 */
trait StampsBusinessDay
{
    protected static function bootStampsBusinessDay(): void
    {
        static::creating(function (Model $model) {
            if ($model->getAttribute('business_date') === null) {
                $model->setAttribute('business_date', BusinessDay::dateFor($model->businessInstant()));
            }

            if (method_exists($model, 'stampCompletion')) {
                $model->stampCompletion();
            }
        });

        static::updating(function (Model $model) {
            if (method_exists($model, 'stampCompletion')) {
                $model->stampCompletion();
            }
        });
    }

    /** Instan yang menentukan hari bisnis saat baris dibuat. */
    protected function businessInstant(): CarbonInterface
    {
        return $this->getAttribute('created_at') ?? now();
    }
}
