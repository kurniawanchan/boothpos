<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Model;

/**
 * 048 / S3 — satu persetujuan di titik aksi (research D11). Hanya HASH token yang disimpan; token asli
 * dikembalikan sekali ke peminta. Baris dibuat dan dikonsumsi lewat {@see \App\Services\OverrideGate}
 * saja — tidak pernah diedit atau dihapus, supaya jejak persetujuan utuh.
 */
class Override extends Model
{
    use BelongsToOutlet, HasDataMode;

    protected $fillable = [
        'action', 'subject_hash', 'token_hash', 'requested_by', 'approved_by', 'reason',
        'issued_at', 'expires_at', 'consumed_at', 'consumed_by_ref',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
