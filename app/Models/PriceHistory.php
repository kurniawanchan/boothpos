<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 043-products-page-redesign — riwayat harga dasar per varian, append-only, ditulis HANYA oleh
 * PriceService (research.md D7). Tabel `price_history` (tunggal), maka nama tabel ditetapkan eksplisit.
 */
class PriceHistory extends Model
{
    use HasDataMode;

    public const UPDATED_AT = null;

    protected $table = 'price_history';

    protected $fillable = ['variant_id', 'outlet_id', 'old_price', 'new_price', 'source', 'user_id', 'approved_by', 'effective_at', 'release_id', 'change_id', 'note'];

    protected function casts(): array
    {
        return ['old_price' => 'decimal:2', 'new_price' => 'decimal:2', 'effective_at' => 'datetime'];
    }

    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed(); }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
}
