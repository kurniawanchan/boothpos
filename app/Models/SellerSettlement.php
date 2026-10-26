<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use App\Support\ReportSplit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 076-settlement-tab-session-branding — satu laporan penyelesaian (settlement)
 * antara toko dan SATU seller untuk SATU event. Berbeda dari ArtistSettlement
 * (cache Rekap Seller POS-only, fitur 040) — keduanya sengaja terpisah.
 *
 * Nilai turunan (arah, saldo, status pembayaran, status tampilan) TIDAK
 * disimpan: dihitung dari kolom + pembayaran + pengakuan saat dibaca, sama
 * seperti PaymentSummary, supaya tidak ada dua sumber kebenaran.
 */
class SellerSettlement extends Model
{
    use HasDataMode;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_FINALIZED = 'finalized';

    protected $fillable = [
        'number', 'event_id', 'artist_id', 'event_name', 'artist_name', 'artist_code',
        'status', 'active_slot', 'pos_units', 'pos_revenue', 'preorder_units', 'preorder_revenue',
        'revenue_total', 'adjustments_total', 'net_amount', 'paid_amount',
        'memo_preorder_uncollected', 'memo_cogs_pos', 'memo_cogs_preorder',
        'finalized_at', 'finalized_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'pos_revenue' => 'decimal:2',
            'preorder_revenue' => 'decimal:2',
            'revenue_total' => 'decimal:2',
            'adjustments_total' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'memo_preorder_uncollected' => 'decimal:2',
            'memo_cogs_pos' => 'decimal:2',
            'memo_cogs_preorder' => 'decimal:2',
            'finalized_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class)->withTrashed();
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class)->withTrashed();
    }

    public function sources(): HasMany
    {
        return $this->hasMany(SellerSettlementSource::class, 'settlement_id')->orderBy('source_date')->orderBy('id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(SellerSettlementAdjustment::class, 'settlement_id')->orderBy('id');
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(SellerSettlementAcknowledgement::class, 'settlement_id')->orderByDesc('id');
    }

    /** Pengakuan yang masih berlaku (belum digantikan); paling banyak satu. */
    public function currentAcknowledgement(): HasOne
    {
        return $this->hasOne(SellerSettlementAcknowledgement::class, 'settlement_id')->whereNull('superseded_at');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SellerSettlementPayment::class, 'settlement_id')->orderBy('paid_at')->orderBy('id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }

    public function netCents(): int
    {
        return ReportSplit::cents($this->net_amount);
    }

    public function revenueCents(): int
    {
        return ReportSplit::cents($this->revenue_total);
    }

    public function paidCents(): int
    {
        return ReportSplit::cents($this->paid_amount);
    }

    /** Sisa yang masih harus dibayar, dalam sen (selalu >= 0). */
    public function balanceCents(): int
    {
        return max(0, abs($this->netCents()) - $this->paidCents());
    }

    public function balance(): string
    {
        return ReportSplit::money($this->balanceCents());
    }

    /** store_pays_seller | seller_pays_store | none — dari tanda net, tidak disimpan. */
    public function direction(): string
    {
        return match (true) {
            $this->netCents() > 0 => 'store_pays_seller',
            $this->netCents() < 0 => 'seller_pays_store',
            default => 'none',
        };
    }

    /** net_zero | unpaid | partially_paid | settled */
    public function paymentStatus(): string
    {
        if ($this->netCents() === 0) {
            return 'net_zero';
        }

        return match (true) {
            $this->paidCents() <= 0 => 'unpaid',
            $this->balanceCents() > 0 => 'partially_paid',
            default => 'settled',
        };
    }

    /**
     * draft | finalized | acknowledged | partially_paid | settled.
     * "settled" dan "partially_paid" hanya tampil setelah settlement diakui
     * seller; sebelum itu statusnya tetap "finalized" (rincian bayar ada di
     * paymentStatus()).
     */
    public function displayStatus(bool $acknowledged): string
    {
        if ($this->isDraft()) {
            return 'draft';
        }
        if (! $acknowledged) {
            return 'finalized';
        }

        return match ($this->paymentStatus()) {
            'settled', 'net_zero' => 'settled',
            'partially_paid' => 'partially_paid',
            default => 'acknowledged',
        };
    }
}
