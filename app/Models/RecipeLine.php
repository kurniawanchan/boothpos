<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 043-products-page-redesign — baris bahan resep. Satuan = satuan bahan (tanpa konversi, O4).
 *
 * 093: bahan boleh berupa bahan baku (`material_id`) ATAU varian produk bahan (`variant_id`), tepat satu (CHECK di
 * DB). Baris varian memakai saldo `variant_outlet_stock` yang sama dengan penjualan langsung; kuantitasnya bilangan
 * bulat per porsi dan tanpa susut (divalidasi RecipeService).
 */
class RecipeLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['recipe_id', 'block_id', 'material_id', 'variant_id', 'quantity', 'shrinkage_pct', 'display_order'];

    protected static function booted(): void
    {
        // 093: baris yang menunjuk varian mengubah himpunan "varian dipakai sebagai bahan"; tulis langsung (impor, tes)
        // tidak lewat RecipeService, jadi memo dibuang di sini juga.
        $forget = fn () => app(\App\Support\IngredientVariants::class)->forget();
        static::saved($forget);
        static::deleted($forget);
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'shrinkage_pct' => 'decimal:2'];
    }

    public function recipe(): BelongsTo { return $this->belongsTo(Recipe::class); }
    public function material(): BelongsTo { return $this->belongsTo(Material::class)->withTrashed(); }

    /** Varian produk bahan; `withTrashed` supaya resep lama tetap bisa disajikan walau varian dihapus lunak. */
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed(); }

    public function isProduct(): bool
    {
        return $this->variant_id !== null;
    }
}
