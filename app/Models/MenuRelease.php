<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 043-products-page-redesign — satu versi menu yang diterbitkan (research.md D17). */
class MenuRelease extends Model
{
    use HasDataMode;

    public const SCHEDULED = 'scheduled';

    public const PUBLISHED = 'published';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['version', 'status', 'publish_at', 'published_at', 'note', 'requested_by', 'approved_by', 'released_by'];

    protected function casts(): array
    {
        return ['publish_at' => 'datetime', 'published_at' => 'datetime', 'version' => 'integer'];
    }

    public function changes(): HasMany { return $this->hasMany(MenuChange::class, 'release_id'); }

    public function releaser(): BelongsTo { return $this->belongsTo(User::class, 'released_by'); }
}
