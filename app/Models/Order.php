<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'order_number', 'event_id', 'session_id', 'customer_id', 'user_id', 'channel',
        'subtotal', 'discount_amount', 'total_amount', 'total_cost', 'paid_amount',
        'change_amount', 'status', 'void_reason', 'local_ref', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2', 'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2', 'total_cost' => 'decimal:2',
            'paid_amount' => 'decimal:2', 'change_amount' => 'decimal:2',
        ];
    }

    public function items(): HasMany { return $this->hasMany(OrderItem::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function event(): BelongsTo { return $this->belongsTo(Event::class); }
    public function session(): BelongsTo { return $this->belongsTo(CashierSession::class, 'session_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function cashier(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
}
