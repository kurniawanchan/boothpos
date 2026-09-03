<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasDataMode;

    protected $fillable = [
        'order_id', 'preorder_id', 'purchase_order_id', 'channel_id', 'method', 'purpose', 'amount',
        'verification', 'verified_by', 'verified_at', 'reject_reason', 'paid_at', 'notes',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'verified_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function preorder(): BelongsTo { return $this->belongsTo(Preorder::class); }
    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class); }
    public function channel(): BelongsTo { return $this->belongsTo(PaymentChannel::class, 'channel_id'); }
    public function proofs(): HasMany { return $this->hasMany(PaymentProof::class); }
}
