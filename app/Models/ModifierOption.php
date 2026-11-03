<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 028 US3 — satu pilihan dalam grup modifier; `price_delta` bertanda (boleh negatif). */
class ModifierOption extends Model
{
    use HasDataMode, HasFactory;

    protected $fillable = ['modifier_group_id', 'name', 'price_delta', 'min_qty', 'max_qty', 'display_order', 'is_active'];

    // 089: jumlah bawaan 1..1 = perilaku lama (ada atau tidak ada). `max_qty` NULL = tanpa batas atas.
    protected $attributes = ['price_delta' => 0, 'min_qty' => 1, 'max_qty' => 1, 'display_order' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return ['price_delta' => 'decimal:2', 'min_qty' => 'integer', 'max_qty' => 'integer', 'is_active' => 'boolean', 'display_order' => 'integer'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }

    /** 087 — bahan yang dipakai pilihan ini per porsi. */
    public function materials(): HasMany
    {
        return $this->hasMany(ModifierOptionMaterial::class);
    }
}
