<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreorderItem extends Model
{
    use HasDataMode;

    protected $fillable = [
        'preorder_id', 'variant_id', 'artist_id', 'sku_snapshot',
        'name_snapshot', 'qty', 'cost_price', 'sell_price', 'line_total',
    ];

    protected function casts(): array
    {
        return ['cost_price' => 'decimal:2', 'sell_price' => 'decimal:2', 'line_total' => 'decimal:2'];
    }

    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'variant_id'); }

    // 013-preorder-list-filters-receipt (T002) — relasi ke penjual (artist)
    // per item; kolom artist_id sudah ada & diisi PreorderService, ini
    // hanya menambahkan relasi Eloquent yang belum ada.
    public function artist(): BelongsTo { return $this->belongsTo(Artist::class, 'artist_id'); }
}
