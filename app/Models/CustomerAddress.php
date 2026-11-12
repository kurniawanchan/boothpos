<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customer commerce — alamat pengiriman tersimpan milik satu akun pelanggan.
 *
 * Tanpa `data_mode` dan tanpa scope: baris ini HANYA boleh dijangkau lewat
 * `$account->addresses()`. Mencarinya langsung dengan id (mis. rule `exists:`
 * di FormRequest) akan menembus batas kepemilikan dan batas DEMO/LIVE.
 *
 * "Paling banyak satu alamat utama per akun" dijaga service dalam transaksi
 * (bukan di sini dan bukan constraint DB).
 */
class CustomerAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'label',
        'recipient_name',
        'phone',
        'address_line',
        'city',
        'province',
        'postal_code',
        'notes',
        'is_default',
    ];

    /** Kolom yang disalin ke `customer_orders.shipping_address` saat checkout. */
    public const SNAPSHOT_FIELDS = [
        'recipient_name', 'phone', 'address_line', 'city', 'province', 'postal_code', 'notes',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'customer_account_id');
    }

    /** @return array<string,string|null> snapshot alamat untuk pesanan (urutan kunci tetap) */
    public function toSnapshot(): array
    {
        return $this->only(self::SNAPSHOT_FIELDS);
    }
}
