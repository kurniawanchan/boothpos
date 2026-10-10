<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 028 — meja makan. Status terisi/kosong TIDAK disimpan: ia diturunkan dari
 * ada-tidaknya order 'open' pada meja ini (lihat openTab()).
 */
class DiningTable extends Model
{
    use HasDataMode, HasFactory;

    protected $fillable = ['name', 'area', 'seats', 'display_order', 'is_active'];

    // Default DB tidak terlihat pada model hasil create().
    protected $attributes = ['area' => '', 'seats' => 2, 'display_order' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return ['seats' => 'integer', 'display_order' => 'integer', 'is_active' => 'boolean'];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'table_id');
    }

    /** Tab yang sedang terbuka di meja ini (paling banyak satu — dijamin database). */
    public function openTab(): HasOne
    {
        return $this->hasOne(Order::class, 'table_id')->where('status', Order::STATUS_OPEN);
    }
}
