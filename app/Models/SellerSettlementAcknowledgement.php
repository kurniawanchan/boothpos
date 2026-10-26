<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengakuan seller — append-only: yang baru MENGGANTIKAN yang lama lewat
 * `superseded_at`, tidak ada baris yang dihapus (jejak audit).
 */
class SellerSettlementAcknowledgement extends Model
{
    protected $fillable = [
        'settlement_id', 'method', 'signer_name', 'signature_path', 'note',
        'acknowledged_at', 'recorded_by', 'superseded_at', 'superseded_reason',
    ];

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
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
