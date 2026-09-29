<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Preorder extends Model
{
    use HasDataMode;

    protected $fillable = [
        'preorder_number', 'event_id', 'customer_id', 'user_id', 'status', 'dispatch_status', 'invoice_sent_at', 'shipping_at', 'fulfillment',
        'subtotal', 'shipping_cost', 'discount', 'total_amount', 'paid_amount', 'expected_date',
        'pickup_day', 'courier_name', 'cancel_reason', 'notes',
    ];

    /**
     * Cermin default kolom DB. Tanpa ini, model hasil create() (mis. respons
     * POST /preorders) membawa dispatch_status = null sampai di-refresh,
     * karena Eloquent tidak membaca balik default sisi database.
     */
    protected $attributes = ['dispatch_status' => 'pending'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2', 'shipping_cost' => 'decimal:2', 'discount' => 'decimal:2',
            'total_amount' => 'decimal:2', 'paid_amount' => 'decimal:2',
            'expected_date' => 'date', 'pickup_day' => 'date',
            'invoice_sent_at' => 'datetime', 'shipping_at' => 'datetime',
        ];
    }

    public function items(): HasMany { return $this->hasMany(PreorderItem::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function event(): BelongsTo { return $this->belongsTo(Event::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function shipment(): HasOne { return $this->hasOne(Shipment::class); }
    public function notifications(): HasMany { return $this->hasMany(PreorderNotification::class); }

    /**
     * 007-preorder-import-export-notify (US4) — dipakai PreorderController
     * ::show() untuk field `latest_notification` tanpa request terpisah.
     */
    public function latestNotification(): ?PreorderNotification
    {
        return $this->relationLoaded('notifications')
            ? $this->notifications->sortByDesc('sent_at')->first()
            : $this->notifications()->orderByDesc('sent_at')->first();
    }

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

    /** Nilai sah `dispatch_status` — satu sumber untuk validasi request & filter. */
    public const DISPATCH_STATUSES = ['pending', 'invoice_sent', 'shipping'];

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::ALLOWED_TRANSITIONS[$this->status] ?? [], true);
    }

    public function outstanding(): float
    {
        return round((float) $this->total_amount - (float) $this->paid_amount, 2);
    }
}
