<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only. Tidak ada UPDATE/DELETE terhadap tabel ini di aplikasi
 * manapun — koreksi selalu berupa baris baru bertipe 'adjustment'.
 */
class StockMovement extends Model
{
    use HasDataMode;

    public $timestamps = false; // hanya created_at, diisi manual di service

    /**
     * 093 — pemakaian varian sebagai BAHAN resep. Sengaja bukan `sale`/`return`: `reference_id` pada `sale` bermakna id
     * ORDER di sebagian jalur dan id BARIS di jalur lain, sedangkan pemakaian resep SELALU id baris menu penyebab
     * (`reference_type = order_item`); varian pada baris ini adalah bahan, bukan menu.
     */
    public const RECIPE_SALE = 'recipe_sale';

    public const RECIPE_RETURN = 'recipe_return';

    public const RECIPE_WASTE = 'recipe_waste';

    public const RECIPE_TYPES = [self::RECIPE_SALE, self::RECIPE_RETURN, self::RECIPE_WASTE];

    protected $fillable = [
        'variant_id', 'outlet_id', 'type', 'qty_change', 'stock_before', 'stock_after',
        'reference_type', 'reference_id', 'reason', 'user_id', 'created_at',
    ];

    protected static function booted(): void
    {
        // 045 Stage 5 — pergerakan yang dibuat tanpa outlet (data/tes lama; StockService selalu mengisinya) jatuh ke
        // outlet bawaan, sama dengan penulisan langsung stok (OutletStockSync).
        static::creating(function (StockMovement $m) {
            if ($m->outlet_id === null) {
                $m->outlet_id = \App\Support\OutletStockSync::defaultOutlet();
            }
        });
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
