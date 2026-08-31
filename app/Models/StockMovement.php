<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only. Tidak ada UPDATE/DELETE terhadap tabel ini di aplikasi
 * manapun — koreksi selalu berupa baris baru bertipe 'adjustment'.
 */
class StockMovement extends Model
{
    public $timestamps = false; // hanya created_at, diisi manual di service

    protected $fillable = [
        'variant_id', 'type', 'qty_change', 'stock_before', 'stock_after',
        'reference_type', 'reference_id', 'reason', 'user_id', 'created_at',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
