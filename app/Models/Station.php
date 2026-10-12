<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 028 US4 — stasiun persiapan (Dapur, Bar). */
class Station extends Model
{
    use BelongsToOutlet;

    use HasDataMode, HasFactory;

    protected $fillable = ['name', 'display_order', 'is_active'];

    protected $attributes = ['display_order' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return ['display_order' => 'integer', 'is_active' => 'boolean'];
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(KitchenTicket::class);
    }
}
