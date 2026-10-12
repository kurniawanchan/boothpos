<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 045 Stage 6 — satu baris transfer (varian atau bahan). Tidak berubah setelah dikirim, kecuali isian penerimaan. */
class StockTransferLine extends Model
{
    protected $fillable = ['transfer_id', 'kind', 'item_id', 'qty_sent', 'qty_received', 'discrepancy_reason'];

    protected function casts(): array
    {
        return ['qty_sent' => 'decimal:3', 'qty_received' => 'decimal:3'];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'transfer_id');
    }

    /** Selisih yang tercatat (hanya setelah diterima). */
    public function shortfall(): float
    {
        return $this->qty_received === null ? 0.0 : round((float) $this->qty_sent - (float) $this->qty_received, 3);
    }
}
