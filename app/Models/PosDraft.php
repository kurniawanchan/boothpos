<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosDraft extends Model
{
    use HasDataMode;

    protected $fillable = ['user_id', 'event_id', 'customer_id', 'cart_snapshot', 'label'];

    protected function casts(): array
    {
        return ['cart_snapshot' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
