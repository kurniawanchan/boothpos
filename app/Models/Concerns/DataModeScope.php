<?php

namespace App\Models\Concerns;

use App\Support\ModeGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * 003-seed-demo-live — scope BERNAMA (bukan closure) supaya bisa
 * dilepas eksplisit lewat `Model::withoutGlobalScope(DataModeScope::class)`
 * (mis. audit lintas mode). Lihat research.md Decision 1.
 */
class DataModeScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->getTable().'.data_mode', ModeGate::current());
    }
}
