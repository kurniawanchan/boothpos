<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 028 US4 — satu pengiriman ("kirim") sebuah tab ke SATU stasiun (atau ke "tanpa
 * stasiun"). Penyelesaian tiket TIDAK disimpan: ia turunan dari status item-itemnya.
 */
class KitchenTicket extends Model
{
    use HasDataMode;

    protected $fillable = ['order_id', 'station_id', 'round_no', 'sent_by', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'round_no' => 'integer'];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function station(): BelongsTo { return $this->belongsTo(Station::class); }
    public function sender(): BelongsTo { return $this->belongsTo(User::class, 'sent_by'); }
    public function items(): HasMany { return $this->hasMany(KitchenTicketItem::class); }
}
