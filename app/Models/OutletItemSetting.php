<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 045 Stage 3 — relasi material outlet × produk: apakah outlet menjual produk itu (D10). Satu baris per
 * pasangan; dibuat OutletMenuService, jangan membuat/menghapus baris di tempat lain.
 */
class OutletItemSetting extends Model
{
    use HasDataMode;

    protected $fillable = ['outlet_id', 'product_id', 'is_carried'];

    protected function casts(): array
    {
        return ['is_carried' => 'boolean'];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
