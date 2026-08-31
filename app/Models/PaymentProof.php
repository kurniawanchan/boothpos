<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentProof extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'proof_token', 'payment_id', 'file_path', 'original_name',
        'mime_type', 'file_size', 'captured_via', 'uploaded_by', 'created_at',
    ];

    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
}
