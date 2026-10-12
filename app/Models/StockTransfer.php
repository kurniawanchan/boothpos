<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 045 Stage 6 — transfer stok antar outlet. Hanya StockTransferService yang menulisnya (dan pergerakan stok terkait).
 * Tanpa OutletScope: keterlihatan = salah satu outlet (asal/tujuan) boleh dilihat pemanggil, ditegakkan service/controller.
 */
class StockTransfer extends Model
{
    use HasDataMode;

    public const IN_TRANSIT = 'in_transit';

    public const RECEIVED = 'received';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['number', 'status', 'has_discrepancy', 'note', 'client_ref'];

    protected function casts(): array
    {
        return ['has_discrepancy' => 'boolean', 'dispatched_at' => 'datetime', 'received_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class, 'transfer_id');
    }

    public function fromOutlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'from_outlet_id')->withTrashed();
    }

    public function toOutlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'to_outlet_id')->withTrashed();
    }

    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
