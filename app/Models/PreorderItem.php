<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreorderItem extends Model
{
    protected $fillable = [
        'preorder_id', 'variant_id', 'artist_id', 'sku_snapshot',
        'name_snapshot', 'qty', 'cost_price', 'sell_price', 'line_total',
    ];

    protected function casts(): array
    {
        return ['cost_price' => 'decimal:2', 'sell_price' => 'decimal:2', 'line_total' => 'decimal:2'];
    }

    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'variant_id'); }
}
