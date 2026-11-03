<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu tabel bahan sebuah resep: terikat pada SATU varian dan (opsional) SATU pilihan modifier. `modifier_option_id`
 * kosong = resep dasar varian; berisi = bahan tambahan yang hanya dipotong bila pilihan itu dipesan.
 */
class RecipeBlock extends Model
{
    public $timestamps = false;

    protected $fillable = ['recipe_id', 'variant_id', 'modifier_option_id', 'display_order'];

    public function recipe(): BelongsTo { return $this->belongsTo(Recipe::class); }

    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed(); }

    public function option(): BelongsTo { return $this->belongsTo(ModifierOption::class, 'modifier_option_id'); }

    public function lines(): HasMany { return $this->hasMany(RecipeLine::class, 'block_id')->orderBy('display_order')->orderBy('id'); }
}
