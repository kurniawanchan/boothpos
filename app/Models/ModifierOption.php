<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 028 US3 — satu pilihan dalam grup modifier; `price_delta` bertanda (boleh negatif). */
class ModifierOption extends Model
{
    use HasDataMode, HasFactory;

    protected $fillable = ['modifier_group_id', 'name', 'price_delta', 'display_order', 'is_active'];

    protected $attributes = ['price_delta' => 0, 'display_order' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return ['price_delta' => 'decimal:2', 'is_active' => 'boolean', 'display_order' => 'integer'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }
}
