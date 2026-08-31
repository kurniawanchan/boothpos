<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Preorder extends Model
{
    protected $fillable = [
        'preorder_number', 'event_id', 'customer_id', 'user_id', 'status', 'fulfillment',
        'subtotal', 'shipping_cost', 'total_amount', 'paid_amount', 'expected_date',
        'cancel_reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2', 'shipping_cost' => 'decimal:2',
            'total_amount' => 'decimal:2', 'paid_amount' => 'decimal:2',
            'expected_date' => 'date',
        ];
    }

    public function items(): HasMany { return $this->hasMany(PreorderItem::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function shipment(): HasOne { return $this->hasOne(Shipment::class); }

    /**
     * State machine sesuai uml-pos-mvp.md bagian 6.1. Urutan linear ketat
     * kecuali 'cancelled' yang bisa dicapai dari beberapa status.
     */
    private const ALLOWED_TRANSITIONS = [
        'ordered' => ['dp_paid', 'cancelled'],
        'dp_paid' => ['arrived', 'cancelled'],
        'arrived' => ['settled', 'cancelled'],
        'settled' => ['handed_over'],
        'handed_over' => [],
        'cancelled' => [],
    ];

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::ALLOWED_TRANSITIONS[$this->status] ?? [], true);
    }

    public function outstanding(): float
    {
        return round((float) $this->total_amount - (float) $this->paid_amount, 2);
    }
}
