<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 043-products-page-redesign — perubahan menu yang menunggu rilis (harga, terbit/diskontinu item, tautan
 * modifier). Status: pending → (awaiting_approval → approved|rejected) → released; atau cancelled.
 */
class MenuChange extends Model
{
    use HasDataMode;

    public const KIND_PRICE = 'price';

    public const KIND_ITEM_PUBLISH = 'item_publish';

    public const KIND_ITEM_DISCONTINUE = 'item_discontinue';

    public const KIND_ITEM_REACTIVATE = 'item_reactivate';

    public const KIND_MODIFIER_LINK = 'modifier_link';

    public const PENDING = 'pending';

    public const AWAITING_APPROVAL = 'awaiting_approval';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const RELEASED = 'released';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['kind', 'subject_type', 'subject_id', 'outlet_id', 'payload', 'effective_from', 'status', 'requested_by', 'approval_id', 'release_id', 'batch_id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'effective_from' => 'date'];
    }

    /** Yang boleh ikut rilis: tidak butuh persetujuan, atau sudah disetujui. */
    public function scopeReleasable(Builder $q): Builder
    {
        return $q->whereIn('status', [self::PENDING, self::APPROVED]);
    }

    public function scopeUnreleased(Builder $q): Builder
    {
        return $q->whereIn('status', [self::PENDING, self::AWAITING_APPROVAL, self::APPROVED]);
    }

    public function approval(): BelongsTo { return $this->belongsTo(Approval::class); }

    public function release(): BelongsTo { return $this->belongsTo(MenuRelease::class, 'release_id'); }

    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
}
