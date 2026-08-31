<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'variant_id', 'artist_id', 'sku_snapshot', 'name_snapshot',
        'qty', 'cost_price', 'sell_price', 'discount_amount', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2', 'sell_price' => 'decimal:2',
            'discount_amount' => 'decimal:2', 'line_total' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'variant_id'); }
    public function artist(): BelongsTo { return $this->belongsTo(Artist::class); }
}
