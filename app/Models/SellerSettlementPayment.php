<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pembayaran settlement (uang ke seller, atau dari seller bila net negatif).
 * SENGAJA bukan baris `payments` (buku kas penerimaan pelanggan) — lihat
 * plan.md "Complexity Tracking". Satu-satunya penulis: SellerSettlementService.
 */
class SellerSettlementPayment extends Model
{
    protected $fillable = [
        'settlement_id', 'client_ref', 'amount', 'paid_at', 'method',
        'reference', 'notes', 'proof_path', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'date',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(SellerSettlement::class, 'settlement_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
