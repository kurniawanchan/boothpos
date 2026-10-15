<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 048 / S2 — rincian kas awal per pecahan (mis. 3 × Rp 50.000). Jumlah seluruh baris = `opening_cash`
 * shift; `opening_cash` tetap disimpan agar laporan lama tidak berubah. Ikut aksesnya lewat shift induk
 * (tidak memakai OutletScope sendiri).
 */
class SessionOpeningDenomination extends Model
{
    use HasDataMode;

    public $timestamps = false;

    protected $fillable = ['session_id', 'denomination', 'qty'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashierSession::class, 'session_id');
    }
}
