<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 043-products-page-redesign — tanda "86": item tidak bisa dipesan sampai akhir hari bisnis atau sampai
 * dibatalkan. Aktif = belum dibatalkan DAN `expires_at` masih di depan; kedaluwarsa hanya perbandingan
 * waktu saat dibaca, tanpa job (research.md D6). Ditulis hanya oleh AvailabilityService.
 */
class ItemAvailabilityMark extends Model
{
    use HasDataMode;

    protected $fillable = ['product_id', 'outlet_id', 'reason_code_id', 'note', 'marked_by', 'marked_at', 'expires_at', 'cleared_at', 'cleared_by'];

    protected function casts(): array
    {
        return ['marked_at' => 'datetime', 'expires_at' => 'datetime', 'cleared_at' => 'datetime'];
    }

    /**
     * 045 Stage 3 — tanda yang berlaku di sebuah outlet: tanda GLOBAL (outlet_id NULL: tanda lama dan instalasi satu
     * outlet) atau tanda outlet itu. `null` = tanpa konteks outlet: semua tanda aktif dihitung.
     */
    public function scopeAppliesTo(Builder $q, ?int $outletId): Builder
    {
        return $outletId === null
            ? $q
            : $q->where(fn ($w) => $w->whereNull('item_availability_marks.outlet_id')->orWhere('item_availability_marks.outlet_id', $outletId));
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('item_availability_marks.cleared_at')->where('item_availability_marks.expires_at', '>', now());
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class)->withTrashed(); }

    public function reason(): BelongsTo { return $this->belongsTo(ReasonCode::class, 'reason_code_id'); }

    public function marker(): BelongsTo { return $this->belongsTo(User::class, 'marked_by'); }
}
