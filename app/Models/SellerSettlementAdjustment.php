<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `amount` adalah pengaruh BERTANDA terhadap net: potongan (deduction)
 * disimpan negatif, penyesuaian (adjustment) boleh positif/negatif.
 */
class SellerSettlementAdjustment extends Model
{
    protected $fillable = [
        'settlement_id', 'kind', 'label', 'reason', 'basis', 'amount', 'post_finalization', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'post_finalization' => 'boolean',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(SellerSettlement::class, 'settlement_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
