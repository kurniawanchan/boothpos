<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\HasDataMode;
use App\Models\Concerns\StampsBusinessDay;
use App\Support\BusinessDay;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use BelongsToOutlet;

    use HasDataMode, StampsBusinessDay;

    // 028 — gunakan konstanta ini di kode baru. `cashier_sessions.status`
    // juga memakai literal 'open', jadi mencari string 'open' mencampur dua
    // konsep berbeda.
    public const STATUS_OPEN = 'open';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_VOIDED = 'voided';

    public const SERVICE_RETAIL = 'retail';
    public const SERVICE_DINE_IN = 'dine_in';
    public const SERVICE_TAKEAWAY = 'takeaway';

    protected $fillable = [
        'order_number', 'event_id', 'session_id', 'customer_id', 'user_id', 'channel',
        'subtotal', 'discount_amount', 'total_amount', 'total_cost', 'paid_amount',
        'change_amount', 'status', 'void_reason', 'local_ref', 'notes',
        'service_type', 'table_id', 'tab_label', 'split_from_order_id',
    ];

    // Default DB tidak terlihat pada model yang dikembalikan create();
    // dicerminkan di sini (pola yang sama dengan Preorder::$attributes).
    protected $attributes = [
        'status' => self::STATUS_COMPLETED,
        'service_type' => self::SERVICE_RETAIL,
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2', 'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2', 'total_cost' => 'decimal:2',
            'paid_amount' => 'decimal:2', 'change_amount' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * 042-dashboard-revamp — saat order MENJADI penjualan (dibuat langsung `completed`, atau tab yang
     * dilunasi/dipecah), catat instannya dan tetapkan ulang tanggal bisnisnya. Tab yang dibuka 23.30
     * dan dilunasi 00.20 dihitung pada hari pelunasan, sama seperti laporan menghitung penjualan
     * saat selesai. Order yang di-void mempertahankan tanggal terakhirnya.
     */
    public function stampCompletion(): void
    {
        if ($this->getAttribute('status') !== self::STATUS_COMPLETED || $this->getAttribute('completed_at') !== null) {
            return;
        }

        if ($this->exists && ! $this->isDirty('status')) {
            return; // baris lama yang sudah completed tanpa completed_at diisi oleh backfill, bukan di sini
        }

        $at = $this->exists ? now() : ($this->getAttribute('created_at') ?? now());
        $this->setAttribute('completed_at', $at);
        $this->setAttribute('business_date', BusinessDay::dateFor($at));
    }

    public function items(): HasMany { return $this->hasMany(OrderItem::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function event(): BelongsTo { return $this->belongsTo(Event::class); }
    public function session(): BelongsTo { return $this->belongsTo(CashierSession::class, 'session_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function cashier(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }

    /** 028 — ringkasan pembayaran turunan (lihat App\Support\PaymentSummary). */
    public function paymentSummary(): array
    {
        return \App\Support\PaymentSummary::for($this);
    }
    public function table(): BelongsTo { return $this->belongsTo(DiningTable::class, 'table_id'); }
    public function splitFrom(): BelongsTo { return $this->belongsTo(self::class, 'split_from_order_id'); }

    /** 028 — tiket dapur yang berasal dari tab ini. */
    public function tickets(): HasMany { return $this->hasMany(KitchenTicket::class); }

    /** 028 — seluruh item tiket dapur tab ini (untuk hitungan "siap saji" di lantai). */
    public function ticketItems(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(KitchenTicketItem::class, KitchenTicket::class, 'order_id', 'kitchen_ticket_id');
    }

    public function isOpen(): bool { return $this->status === self::STATUS_OPEN; }

    protected function outletFromParent(): ?int
    {
        // Order mewarisi outlet SHIFT-nya (D7/D8): outlet order tidak pernah datang dari input.
        if ($this->session_id === null) {
            return null;
        }

        return CashierSession::withoutGlobalScopes()->whereKey($this->session_id)->value('outlet_id');
    }
}
