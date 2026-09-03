<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use HasDataMode, HasFactory, SoftDeletes;

    protected $fillable = [
        'po_number', 'vendor_id', 'status', 'ordered_at', 'received_at', 'paid_at',
        'cancelled_at', 'cancel_reason', 'subtotal', 'total_amount', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'ordered_at' => 'datetime',
            'received_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Reuse Payment (bukan tabel/model baru) — payments.purchase_order_id
     * adalah kolom nullable baru yang sama polanya dengan order_id/
     * preorder_id yang sudah ada, supaya PaymentRecorder tetap satu-
     * satunya jalur sah pencatatan pembayaran (Constitution I).
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function paidAmount(): string
    {
        return (string) $this->payments()->sum('amount');
    }

    /**
     * Sama persis dengan Preorder::ALLOWED_TRANSITIONS/canTransitionTo() —
     * lihat research.md R5. received/paid/cancelled adalah status akhir.
     */
    private const ALLOWED_TRANSITIONS = [
        'draft' => ['ordered', 'cancelled'],
        'ordered' => ['received', 'cancelled'],
        'received' => ['paid'],
        'paid' => [],
        'cancelled' => [],
    ];

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::ALLOWED_TRANSITIONS[$this->status] ?? [], true);
    }
}
