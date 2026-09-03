<?php

namespace App\Models\Concerns;

use App\Support\ModeGate;

/**
 * 003-seed-demo-live — satu-satunya tempat logika penyaringan DEMO/LIVE
 * diterapkan (Constitution I: satu jalur sanksi per concern). Dipasang
 * pada setiap model data bisnis/transaksional (lihat data-model.md untuk
 * daftar 20 tabel) — TIDAK pada users/roles/settings/activity_logs/
 * payment_channels.
 *
 * - Baris baru dicap otomatis dengan mode yang sedang aktif SAAT DIBUAT,
 *   kecuali caller sudah mengisi `data_mode` sendiri secara eksplisit
 *   (dipakai SakanaFridgeDemoSeeder yang memaksa 'demo' lewat
 *   ModeGate::runAs(), lihat research.md Decision 2).
 * - Setiap query lewat model ini otomatis disaring ke mode yang sedang
 *   aktif; dilepas eksplisit lewat
 *   `Model::withoutGlobalScope(DataModeScope::class)` bila benar-benar
 *   perlu lintas mode (audit, laporan lintas-mode masa depan).
 */
trait HasDataMode
{
    protected static function bootHasDataMode(): void
    {
        static::addGlobalScope(new DataModeScope);

        static::creating(function ($model) {
            $model->data_mode = $model->data_mode ?? ModeGate::current();
        });
    }
}
