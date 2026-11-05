<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 059 — satu persetujuan seller atas item sebuah penjualan, satu siklus. Append-only: tidak ada baris atau
 * berkas yang diubah isinya atau dihapus; satu-satunya perubahan sah adalah menandai baris tak berlaku
 * (`invalidated_at`, `invalidation_reason`, `active_slot` NULL) oleh OrderApprovalService::reconcile().
 *
 * Sengaja TANPA HasDataMode (ikut mode order-nya, lihat migrasi) dan tanpa SellerScope: header penjualan
 * tampil utuh bagi pengguna ber-seller (058 Q1:B), jadi status seller lain ikut terlihat.
 */
class OrderApproval extends Model
{
    protected $fillable = [
        'order_id', 'artist_id', 'artist_name', 'method', 'image_path', 'image_mime', 'items_hash', 'items_snapshot',
        'approved_by', 'approver_name', 'on_behalf', 'approved_at', 'invalidated_at', 'invalidation_reason', 'active_slot',
    ];

    protected function casts(): array
    {
        return [
            'items_snapshot' => 'array', 'on_behalf' => 'boolean',
            'approved_at' => 'datetime', 'invalidated_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('invalidated_at');
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function artist(): BelongsTo { return $this->belongsTo(Artist::class)->withTrashed(); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
}
