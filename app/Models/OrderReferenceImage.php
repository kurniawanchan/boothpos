<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 055 — satu gambar referensi transaksi milik satu penjualan POS.
 *
 * Sengaja TANPA HasDataMode: gambar tak bermakna di luar order-nya dan selalu dijangkau lewat Order yang
 * sudah ber-scope DEMO/LIVE (route `{order}` + scoped binding `{image}`). Clone LIVE→DEMO membawanya lewat
 * `via orders.order_id` di CloneRegistry. Satu-satunya penulis: OrderReferenceService (baris hanya
 * ditambah/dihapus, tidak pernah diubah).
 */
class OrderReferenceImage extends Model
{
    protected $fillable = ['order_id', 'path', 'mime', 'size_bytes', 'position', 'uploaded_by'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'position' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
