<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * F13.4 — append-only, sama seperti StockMovement. Tidak ada UPDATE/DELETE
 * terhadap tabel ini di aplikasi manapun; setiap baris adalah jejak audit
 * satu tindakan sensitif (hapus data, penyesuaian stok, ubah harga).
 */
class ActivityLog extends Model
{
    public $timestamps = false; // hanya created_at, diisi manual di ActivityLogger

    protected $fillable = [
        'user_id', 'action', 'entity_type', 'entity_id',
        'description', 'old_values', 'new_values', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
