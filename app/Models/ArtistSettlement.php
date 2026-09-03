<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtistSettlement extends Model
{
    use HasDataMode;

    protected $fillable = [
        'event_id', 'artist_id', 'total_sales', 'total_units', 'deduction',
        'payable_amount', 'paid_amount', 'status', 'calculated_at', 'paid_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'total_sales' => 'decimal:2',
            'deduction' => 'decimal:2',
            'payable_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'calculated_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }
}
