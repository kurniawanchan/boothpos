<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customer commerce — pengiriman kurir sebuah pesanan pelanggan (paling
 * banyak satu per pesanan; pesanan ambil-di-tempat tidak pernah punya).
 *
 * Sengaja BUKAN tabel `shipments` (anak satu-ke-satu dari pre-order).
 * Dicatat staf, bukan telemetri kurir. Hanya ditulis oleh
 * CustomerOrderTransitionService; `shipping_cost` adalah snapshot nilai
 * pesanan saat dikirim. Tidak ada kolom URL: tautan pelacakan dibentuk server
 * dari config('customer_commerce.tracking_url_templates'), tidak pernah disimpan.
 *
 * Tanpa `data_mode`: hanya dijangkau lewat pesanannya.
 */
class CustomerOrderShipment extends Model
{
    use HasFactory;

    protected $fillable = [
        'courier_name',
        'service_name',
        'tracking_number',
        'shipping_cost',
        'dispatched_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'shipping_cost' => 'decimal:2',
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomerOrder::class, 'customer_order_id');
    }
}
