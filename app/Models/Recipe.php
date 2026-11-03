<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use App\Support\BusinessDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu resep: bahan per porsi untuk satu atau beberapa varian satu produk.
 *
 * 089 (keputusan pemilik 2026-11-02): resep TIDAK lagi berversi — diubah di tempat. Sebuah varian boleh punya banyak
 * resep aktif dan semuanya dijumlahkan (potong stok saat pesanan selesai, dan harga modal). Resep aktif =
 * tidak diarsipkan dan `effective_from` ≤ hari bisnis ini. Arsip (`archived_at`) = sisa versi lama hasil migrasi:
 * baca-saja, tidak memotong stok.
 */
class Recipe extends Model
{
    use HasDataMode;

    protected $fillable = ['product_id', 'name', 'yield', 'effective_from', 'note', 'cost_applied_at', 'archived_at', 'created_by'];

    protected function casts(): array
    {
        return ['yield' => 'decimal:3', 'effective_from' => 'date', 'cost_applied_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    /** Resep yang boleh memotong stok hari ini: bukan arsip dan sudah berlaku menurut hari bisnis (042). */
    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('archived_at')->whereDate('effective_from', '<=', BusinessDay::today());
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class)->withTrashed(); }

    public function lines(): HasMany { return $this->hasMany(RecipeLine::class)->orderBy('display_order')->orderBy('id'); }

    public function blocks(): HasMany { return $this->hasMany(RecipeBlock::class)->orderBy('display_order')->orderBy('id'); }

    public function variants(): BelongsToMany { return $this->belongsToMany(ProductVariant::class, 'recipe_variants', 'recipe_id', 'variant_id'); }

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
