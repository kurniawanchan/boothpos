<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shipment extends Model
{
    use HasDataMode;

    protected $fillable = [
        'preorder_id', 'courier_name', 'tracking_number', 'shipping_cost',
        'recipient_name', 'recipient_phone', 'address_line', 'city',
        'province', 'postal_code', 'status', 'shipped_at', 'delivered_at', 'notes',
    ];

    protected function casts(): array
    {
        return ['shipping_cost' => 'decimal:2', 'shipped_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function preorder(): BelongsTo { return $this->belongsTo(Preorder::class); }
}
