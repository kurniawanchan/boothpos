<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Customer commerce — pesanan dari aplikasi pelanggan. BUKAN penjualan POS
 * (`orders`) dan bukan pre-order: ia permintaan yang menunggu konfirmasi staf
 * dan selesai sebagai catatannya sendiri (laporan POS tidak tersentuh).
 *
 * Aturan yang tidak boleh dilanggar:
 *  - `status`, riwayat status, dan pengiriman HANYA ditulis oleh
 *    App\Services\CustomerCommerce\CustomerOrderTransitionService. Model ini
 *    sengaja tidak punya method transisi; pelanggan tidak punya jalur untuk
 *    mengubah status.
 *  - Stok tidak bergerak saat pesanan dibuat. `stock_deducted_at` terisi saat
 *    staf mengonfirmasi (StockService::applyMovement) dan menentukan apakah
 *    pembatalan mengembalikan stok.
 *  - Kontak, alamat, nama/SKU/harga item adalah snapshot; jangan dibaca ulang
 *    dari profil atau varian.
 *
 * HasDataMode: ya (dicari langsung lewat nomor dan daftar staf).
 * HasSellerScope: TIDAK — SellerScope belum punya aturan untuk tabel ini
 * (akan melempar LogicException bagi staf terikat seller); rute staf memakai
 * `seller.forbidden` sampai pertanyaan multi-seller diputuskan.
 *
 * Tiga tabel anaknya tidak punya `data_mode`: query mentah (DB::table) pada
 * anak WAJIB bergabung ke tabel ini dan menyaring `data_mode` sendiri.
 */
class CustomerOrder extends Model
{
    use HasDataMode, HasFactory;

    public const STATUS_AWAITING_CONFIRMATION = 'awaiting_confirmation';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_PACKING = 'packing';

    public const STATUS_READY_FOR_PICKUP = 'ready_for_pickup';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_PICKED_UP = 'picked_up';

    public const STATUS_CANCELLED = 'cancelled';

    /** Urutan sama dengan enum di basis data (dijaga CustomerCommerceModelTest). */
    public const STATUSES = [
        self::STATUS_AWAITING_CONFIRMATION,
        self::STATUS_CONFIRMED,
        self::STATUS_PACKING,
        self::STATUS_READY_FOR_PICKUP,
        self::STATUS_SHIPPED,
        self::STATUS_DELIVERED,
        self::STATUS_PICKED_UP,
        self::STATUS_CANCELLED,
    ];

    public const FULFILMENT_PICKUP = 'pickup';

    public const FULFILMENT_COURIER = 'courier';

    public const FULFILMENT_METHODS = [self::FULFILMENT_PICKUP, self::FULFILMENT_COURIER];

    protected $fillable = [
        'order_number',
        'customer_account_id',
        'customer_id',
        'fulfilment_method',
        'status',
        'contact_name',
        'contact_phone',
        'contact_email',
        'shipping_address',
        'subtotal',
        'shipping_cost',
        'total',
        'notes',
        'cancel_reason',
        'idempotency_key',
        'idempotency_hash',
        'stock_deducted_at',
        'confirmed_at',
        'completed_at',
        'cancelled_at',
    ];

    /** Kunci idempotensi adalah detail internal checkout; tidak pernah ikut serialisasi bawaan. */
    protected $hidden = ['idempotency_key', 'idempotency_hash'];

    /**
     * Nilai bawaan kolom DB tidak terlihat pada model hasil create() (jebakan
     * yang sama dengan Preorder::$attributes), jadi dicerminkan di sini.
     */
    protected $attributes = [
        'status' => self::STATUS_AWAITING_CONFIRMATION,
        'shipping_cost' => 0,
    ];

    protected function casts(): array
    {
        return [
            'shipping_address' => 'array',
            'subtotal' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'total' => 'decimal:2',
            'stock_deducted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function isCourier(): bool
    {
        return $this->fulfilment_method === self::FULFILMENT_COURIER;
    }

    public function isPickup(): bool
    {
        return $this->fulfilment_method === self::FULFILMENT_PICKUP;
    }

    /**
     * Ongkir "menunggu konfirmasi toko": tidak ada tarif otomatis di v1, jadi
     * pesanan kurir lahir dengan ongkir 0 dan toko menetapkannya saat
     * mengonfirmasi. Selama itu `total` belum final dan aplikasi harus
     * mengatakannya; setelah dikonfirmasi (atau dibatalkan) angkanya tetap.
     * Satu definisi untuk resource pelanggan dan staf.
     */
    public function shippingCostPending(): bool
    {
        return $this->isCourier() && $this->status === self::STATUS_AWAITING_CONFIRMATION;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'customer_account_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CustomerOrderItem::class)->orderBy('id');
    }

    public function shipment(): HasOne
    {
        return $this->hasOne(CustomerOrderShipment::class);
    }

    /** Riwayat status, terbaru lebih dulu (urutan yang dilihat pelanggan). */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(CustomerOrderStatusEvent::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }
}
