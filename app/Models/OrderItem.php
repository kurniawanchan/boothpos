<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasDataMode;

    protected $fillable = [
        'order_id', 'variant_id', 'home_outlet_id', 'sku_snapshot', 'name_snapshot',
        'qty', 'cost_price', 'sell_price', 'discount_amount', 'line_total',
        'modifiers', 'note', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2', 'sell_price' => 'decimal:2',
            'discount_amount' => 'decimal:2', 'line_total' => 'decimal:2',
            'modifiers' => 'array', 'sent_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'variant_id'); }
    public function homeOutlet(): BelongsTo { return $this->belongsTo(Outlet::class, 'home_outlet_id'); }

    /** 028 — item tiket dapur untuk baris ini (ada setelah baris dikirim). */
    public function ticketItem(): \Illuminate\Database\Eloquent\Relations\HasOne { return $this->hasOne(KitchenTicketItem::class); }
}
