<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 048 / S2 — kas masuk/keluar di luar penjualan (uang kecil, setor bank, tambah modal).
 *
 * Baris hanya dibuat lewat {@see \App\Services\CashMovementService} (satu jalur, di dalam transaksi
 * yang sama dengan catatan aktivitas). Tidak pernah diedit atau dihapus: koreksi dicatat sebagai
 * gerakan baru, supaya jejak kas shift tetap utuh.
 */
class CashMovement extends Model
{
    use BelongsToOutlet, HasDataMode, HasFactory;

    public const IN = 'in';

    public const OUT = 'out';

    /**
     * Alasan yang diterima per arah. Daftar ini satu-satunya sumber: validasi request dan service
     * membacanya dari sini, jadi kode baru harus ditambah di sini dan di lang/*\/events_sessions.php.
     */
    public const REASONS = [
        self::IN => ['float_topup', 'change_exchange', 'other'],
        self::OUT => ['petty_cash', 'supplier', 'bank_deposit', 'other'],
    ];

    /** Alasan yang mewajibkan catatan tertulis. */
    public const REASON_NEEDS_NOTE = 'other';

    protected $fillable = ['session_id', 'direction', 'reason_code', 'reason_note', 'amount', 'created_by'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashierSession::class, 'session_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
