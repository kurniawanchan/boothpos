<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 043-products-page-redesign — baris bahan resep. Satuan = satuan bahan (tanpa konversi, O4). */
class RecipeLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['recipe_id', 'material_id', 'quantity', 'shrinkage_pct', 'display_order'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'shrinkage_pct' => 'decimal:2'];
    }

    public function recipe(): BelongsTo { return $this->belongsTo(Recipe::class); }

    public function material(): BelongsTo { return $this->belongsTo(Material::class)->withTrashed(); }
}
