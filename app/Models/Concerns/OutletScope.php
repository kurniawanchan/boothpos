<?php

namespace App\Models\Concerns;

use App\Support\OutletContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * 045 Stage 2 — penyaringan outlet otomatis untuk setiap model operasional (research.md D5).
 *
 * Hanya aktif di dalam permintaan yang sudah login (OutletContext punya pengguna). Perintah
 * konsol, antrean, dan tes tanpa login tidak disaring. Daftar yang dipakai adalah
 * OutletContext::effectiveIds(): pilihan X-Outlet-Id bila ada, selain itu semua outlet yang boleh.
 */
final class OutletScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(OutletContext::class);

        if ($context->user() === null) {
            return;
        }

        $builder->whereIn($model->qualifyColumn('outlet_id'), $context->effectiveIds());
    }
}
