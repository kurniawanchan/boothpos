<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 063 — satu baris per pembukaan-kembali shift. APPEND-ONLY: hanya CashierSessionReopenService yang
 * menulis, tidak ada jalur ubah/hapus. Sengaja tanpa HasDataMode (ikut mode shift induknya).
 */
class CashierSessionReopen extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'session_id', 'reopened_by', 'reopened_at', 'reason', 'previous_closed_at',
        'previous_closing_cash', 'previous_expected_cash', 'previous_cash_difference',
    ];

    protected function casts(): array
    {
        return [
            'reopened_at' => 'datetime',
            'previous_closed_at' => 'datetime',
            'previous_closing_cash' => 'decimal:2',
            'previous_expected_cash' => 'decimal:2',
            'previous_cash_difference' => 'decimal:2',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashierSession::class, 'session_id');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }
}
