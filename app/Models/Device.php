<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Model;

/**
 * 048 — terminal POS (tablet/telepon) milik satu outlet. `outlet_id` TIDAK fillable: dicap server
 * (BelongsToOutlet / DeviceService), tidak pernah dari input.
 */
class Device extends Model
{
    use BelongsToOutlet, HasDataMode;

    protected $fillable = ['code', 'name', 'app_version', 'os_info', 'last_seen_at', 'clock_skew_seconds', 'paired_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'paired_at' => 'datetime', 'revoked_at' => 'datetime', 'clock_skew_seconds' => 'integer'];
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
