<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOutlet;
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
    use BelongsToOutlet;

    use HasDataMode, HasFactory;

    protected $fillable = ['name', 'area', 'seats', 'display_order', 'is_active'];

    // 090 — nonce QR bersifat rahasia penandatanganan: tidak pernah ikut serialisasi dan tidak fillable.
    protected $hidden = ['qr_nonce'];

    protected static function booted(): void
    {
        // Meja baru selalu punya nonce acak (kolom NOT NULL berbawaan ''); memutarnya = mencabut QR lama.
        static::creating(function (self $table) {
            if (($table->getAttribute('qr_nonce') ?? '') === '') {
                $table->setAttribute('qr_nonce', \Illuminate\Support\Str::random(16));
            }
        });
    }

    // Default DB tidak terlihat pada model hasil create().
    protected $attributes = ['area' => '', 'seats' => 2, 'display_order' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return ['seats' => 'integer', 'display_order' => 'integer', 'is_active' => 'boolean'];
    }

    /**
     * 090 — SATU definisi filter daftar meja (pencarian nama/area, area, status aktif, outlet), dipakai daftar Restaurant
     * Setup dan pembuatan QR massal menurut filter, supaya "semua hasil filter" persis sama dengan yang terlihat di daftar.
     * `outlet_ids` sudah dipotong OutletContext oleh pemanggil (hanya menyempitkan; Konstitusi IV); `[]` = tak satu pun.
     *
     * @param  array{q?:?string, area?:?string, is_active?:mixed, outlet_ids?:?array<int>}  $f
     */
    public function scopeFiltered(\Illuminate\Database\Eloquent\Builder $query, array $f): \Illuminate\Database\Eloquent\Builder
    {
        $term = trim((string) ($f['q'] ?? ''));
        $area = $f['area'] ?? null;

        return $query
            ->when($term !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', '%'.$term.'%')->orWhere('area', 'like', '%'.$term.'%')))
            ->when($area !== null && $area !== '', fn ($w) => $w->where('area', $area))
            ->when(array_key_exists('is_active', $f) && $f['is_active'] !== null && $f['is_active'] !== '', fn ($w) => $w->where('is_active', filter_var($f['is_active'], FILTER_VALIDATE_BOOLEAN)))
            ->when(($f['outlet_ids'] ?? null) !== null, fn ($w) => $w->whereIn('outlet_id', $f['outlet_ids'] ?: [0]));
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
