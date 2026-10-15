<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\HasDataMode;
use App\Models\Concerns\StampsBusinessDay;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashierSession extends Model
{
    use BelongsToOutlet;

    use HasDataMode, HasFactory, StampsBusinessDay;

    protected $fillable = [
        'user_id', 'opened_at', 'closed_at', 'opening_cash',
        'closing_cash', 'expected_cash', 'cash_difference', 'status', 'notes',
        'counted_by_denomination', 'counted_total', 'edc_batch_total', 'variance', 'z_report',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'decimal:2',
            'closing_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'cash_difference' => 'decimal:2',
            'counted_by_denomination' => 'array',
            'counted_total' => 'decimal:2',
            'edc_batch_total' => 'decimal:2',
            'variance' => 'decimal:2',
            'z_report' => 'array',
        ];
    }

    /** Hari bisnis sebuah shift = hari shift dibuka. */
    protected function businessInstant(): CarbonInterface
    {
        return $this->getAttribute('opened_at') ?? $this->getAttribute('created_at') ?? now();
    }


    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'session_id');
    }

    public function openingCashEntries(): HasMany
    {
        return $this->hasMany(SessionOpeningCashEntry::class, 'session_id');
    }

    public function openingDenominations(): HasMany
    {
        return $this->hasMany(SessionOpeningDenomination::class, 'session_id');
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class, 'session_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
