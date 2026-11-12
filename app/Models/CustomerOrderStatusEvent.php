<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customer commerce — riwayat status pesanan pelanggan. APPEND-ONLY, seperti
 * StockMovement: koreksi selalu berupa baris baru, tidak pernah UPDATE/DELETE.
 * Di sini aturan itu juga dipaksa di tingkat model (lihat booted()), karena
 * riwayat inilah yang dilihat pelanggan sebagai kebenaran atas pesanannya.
 *
 * Satu-satunya penulis: CustomerOrderTransitionService. Perubahan nomor resi
 * dan penetapan ongkir juga menjadi baris baru (`event_type`), bukan
 * menimpa baris lama.
 *
 * `note` = teks polos yang boleh dilihat pelanggan. `metadata`, `actor_user_id`
 * dan nama staf adalah detail audit: disembunyikan dari serialisasi bawaan dan
 * hanya boleh ditampilkan resource STAF secara eksplisit.
 *
 * Tanpa `data_mode`: hanya dijangkau lewat pesanannya. Tanpa `updated_at`.
 */
class CustomerOrderStatusEvent extends Model
{
    public const UPDATED_AT = null;

    public const EVENT_STATUS_CHANGED = 'status_changed';

    public const EVENT_TRACKING_UPDATED = 'tracking_updated';

    public const EVENT_SHIPPING_COST_SET = 'shipping_cost_set';

    /** Urutan sama dengan enum di basis data. */
    public const EVENT_TYPES = [
        self::EVENT_STATUS_CHANGED,
        self::EVENT_TRACKING_UPDATED,
        self::EVENT_SHIPPING_COST_SET,
    ];

    public const ACTOR_CUSTOMER = 'customer';

    public const ACTOR_STAFF = 'staff';

    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_TYPES = [self::ACTOR_CUSTOMER, self::ACTOR_STAFF, self::ACTOR_SYSTEM];

    protected $fillable = [
        'status',
        'event_type',
        'note',
        'actor_type',
        'actor_user_id',
        'metadata',
        'occurred_at',
    ];

    protected $hidden = ['metadata', 'actor_user_id'];

    /** Nilai bawaan kolom DB dicerminkan supaya terlihat pada model hasil create(). */
    protected $attributes = [
        'event_type' => self::EVENT_STATUS_CHANGED,
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $refuse = function (): never {
            throw new \LogicException('customer_order_status_events bersifat append-only: baris tidak boleh diubah atau dihapus; tambahkan peristiwa baru.');
        };

        static::updating($refuse);
        static::deleting($refuse);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomerOrder::class, 'customer_order_id');
    }

    /** Staf pelaku (null untuk pelanggan/sistem, atau bila pengguna sudah dihapus). Jangan pernah dikirim ke pelanggan. */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
