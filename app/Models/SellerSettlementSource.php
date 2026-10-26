<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris sumber beku: satu transaksi (order / pre-order) milik seller tersebut.
 * Tanpa HasDataMode — mode-nya ikut induk (lihat CloneRegistry 'via').
 */
class SellerSettlementSource extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'settlement_id', 'source_type', 'source_id', 'source_number', 'source_date',
        'units', 'gross_amount', 'amount', 'uncollected_amount', 'cost_amount',
    ];

    protected function casts(): array
    {
        return [
            'source_date' => 'datetime',
            'gross_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'uncollected_amount' => 'decimal:2',
            'cost_amount' => 'decimal:2',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(SellerSettlement::class, 'settlement_id');
    }
}
