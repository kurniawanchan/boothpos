<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionOpeningCashEntry extends Model
{
    use HasDataMode;

    public $timestamps = false;

    protected $fillable = ['session_id', 'artist_id', 'amount', 'created_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'created_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashierSession::class, 'session_id');
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }
}
