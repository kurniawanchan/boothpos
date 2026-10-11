<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 043-products-page-redesign — persetujuan atas sebuah perubahan (jenis `menu_price`). Aturan SATU jalur:
 * pemutus ≠ peminta, ditegakkan di ApprovalService (bukan di sini).
 */
class Approval extends Model
{
    use HasDataMode;

    public const KIND_MENU_PRICE = 'menu_price';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = ['kind', 'subject_type', 'subject_id', 'requested_by', 'decided_by', 'decision', 'reason_code_id', 'note', 'difference_pct', 'amount', 'decided_at'];

    protected function casts(): array
    {
        return ['difference_pct' => 'decimal:2', 'amount' => 'decimal:2', 'decided_at' => 'datetime'];
    }

    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }

    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }

    public function reason(): BelongsTo { return $this->belongsTo(ReasonCode::class, 'reason_code_id'); }
}
