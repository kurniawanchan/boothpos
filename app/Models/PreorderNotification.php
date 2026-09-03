<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 007-preorder-import-export-notify (US4) — baris ini hanya ditulis
 * sebagai efek samping alur notifikasi (PreorderNotifier), tidak punya
 * permukaan API create/update sendiri.
 */
class PreorderNotification extends Model
{
    protected $fillable = [
        'preorder_id', 'trigger', 'triggered_by_status', 'recipient_email',
        'status', 'error_message', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function preorder(): BelongsTo
    {
        return $this->belongsTo(Preorder::class);
    }
}
