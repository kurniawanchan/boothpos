<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 028 US4 — satu item pada tiket dapur: SNAPSHOT baris order (nama, jumlah, modifier,
 * catatan) + status persiapan. Status hanya maju: new → preparing → ready → served;
 * `cancelled` hanya lewat pembatalan baris (user story 6), bukan endpoint status.
 */
class KitchenTicketItem extends Model
{
    use HasDataMode;

    public const NEW = 'new';
    public const PREPARING = 'preparing';
    public const READY = 'ready';
    public const SERVED = 'served';
    public const CANCELLED = 'cancelled';

    /** Status yang masih "hidup" (belum selesai) — tampil di layar stasiun sampai selesai. */
    public const UNFINISHED = [self::NEW, self::PREPARING, self::READY];

    protected $fillable = [
        'kitchen_ticket_id', 'order_item_id', 'name_snapshot', 'qty', 'unit_price', 'modifiers', 'note', 'status',
        'preparing_at', 'ready_at', 'served_at', 'cancelled_at',
        'preparing_by', 'ready_by', 'served_by', 'cancelled_by', 'cancel_reason',
    ];

    protected $attributes = ['status' => self::NEW];

    protected function casts(): array
    {
        return [
            'qty' => 'integer', 'unit_price' => 'decimal:2', 'modifiers' => 'array',
            'preparing_at' => 'datetime', 'ready_at' => 'datetime', 'served_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo { return $this->belongsTo(KitchenTicket::class, 'kitchen_ticket_id'); }
    public function orderItem(): BelongsTo { return $this->belongsTo(OrderItem::class); }
}
