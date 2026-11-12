<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customer commerce — satu baris pesanan pelanggan. `sku_snapshot`,
 * `name_snapshot` ("nama produk — nama varian") dan `unit_price` adalah
 * catatan saat checkout: perubahan harga/nama/SKU varian sesudahnya tidak
 * mengubah pesanan. Harga selalu dihitung server dari `sell_price` varian.
 *
 * Tanpa `data_mode`: hanya dijangkau lewat pesanannya.
 * Id baris ini adalah `reference_id` pergerakan stok
 * (`reference_type = 'customer_order_item'`) saat konfirmasi dan pembatalan.
 */
class CustomerOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'variant_id',
        'sku_snapshot',
        'name_snapshot',
        'unit_price',
        'qty',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'qty' => 'integer',
            'line_total' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomerOrder::class, 'customer_order_id');
    }

    /**
     * Varian lewat scope biasanya (mode data, seller, belum dihapus): varian
     * yang sudah dihapus lunak atau milik mode lain menghasilkan null, dan
     * pemanggil (konfirmasi staf) harus memperlakukannya sebagai konflik
     * bisnis, bukan galat.
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
