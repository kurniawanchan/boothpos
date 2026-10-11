<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 043-products-page-redesign — satu VERSI resep. Tidak pernah diubah setelah dibuat: perubahan = versi baru
 * (research.md D12). Versi aktif sebuah varian = versi terbaru dengan `effective_from` ≤ hari ini.
 */
class Recipe extends Model
{
    use HasDataMode;

    protected $fillable = ['product_id', 'version', 'yield', 'effective_from', 'note', 'cost_applied_at', 'created_by'];

    protected function casts(): array
    {
        return ['yield' => 'decimal:3', 'effective_from' => 'date', 'version' => 'integer', 'cost_applied_at' => 'datetime'];
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class)->withTrashed(); }

    public function lines(): HasMany { return $this->hasMany(RecipeLine::class)->orderBy('display_order')->orderBy('id'); }

    public function variants(): BelongsToMany { return $this->belongsToMany(ProductVariant::class, 'recipe_variants', 'recipe_id', 'variant_id'); }

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
