<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\HasDataMode;
use App\Models\Concerns\StampsBusinessDay;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 090 — kiriman pesan mandiri pelanggan yang DITAHAN sampai staf memverifikasi pembayaran dan menekan
 * "Kirim ke dapur" (research D1). Baru saat itu baris `orders`, stok, pembayaran, dan tiket dapur dibuat,
 * lewat layanan yang sudah ada. Satu-satunya penulis entitas ini: SelfServiceOrderService.
 *
 * Siklus: awaiting_verification → verified → dispatched; awaiting_verification|verified → rejected (final).
 *
 * `outlet_id`, `table_id`, status, dan semua kolom potret TIDAK fillable: server yang menetapkannya dari token
 * QR yang sudah divalidasi, tidak pernah dari body (Konstitusi IV). `lines` dan total adalah potret saat kirim
 * dan tidak pernah ditulis ulang dari data master.
 */
class SelfServiceOrder extends Model
{
    use BelongsToOutlet;

    use HasDataMode, StampsBusinessDay;

    public const AWAITING = 'awaiting_verification';
    public const VERIFIED = 'verified';
    public const DISPATCHED = 'dispatched';
    public const REJECTED = 'rejected';

    /** Status yang masih menunggu tindakan staf (lencana tab dan polling Android). */
    public const PENDING = [self::AWAITING, self::VERIFIED];

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'tax_rate_snapshot' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'business_date' => 'date',
            'verified_at' => 'datetime',
            'rejected_at' => 'datetime',
            'dispatched_at' => 'datetime',
        ];
    }

    public function table(): BelongsTo { return $this->belongsTo(DiningTable::class, 'table_id')->withoutGlobalScopes(); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function proof(): BelongsTo { return $this->belongsTo(PaymentProof::class, 'proof_id'); }
    public function channel(): BelongsTo { return $this->belongsTo(PaymentChannel::class, 'payment_channel_id'); }
    public function verifier(): BelongsTo { return $this->belongsTo(User::class, 'verified_by'); }
    public function rejecter(): BelongsTo { return $this->belongsTo(User::class, 'rejected_by'); }
    public function dispatcher(): BelongsTo { return $this->belongsTo(User::class, 'dispatched_by'); }

    public function isPending(): bool
    {
        return in_array($this->status, self::PENDING, true);
    }
}
