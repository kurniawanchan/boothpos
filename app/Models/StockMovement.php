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
