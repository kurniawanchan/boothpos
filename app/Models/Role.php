<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Peran yang bisa dikonfigurasi bebas — menggantikan enum users.role yang
 * dulunya cuma 4 nilai tetap. Lihat data-model.md dan research.md Keputusan
 * 1 untuk alasan menu_keys disimpan sebagai satu kolom JSON, bukan tabel
 * junction.
 */
class Role extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'menu_keys',
        'is_system_default',
    ];

    protected function casts(): array
    {
        return [
            'menu_keys' => 'array',
            'is_system_default' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * True bila peran ini punya akses ke SALAH SATU dari kunci menu yang
     * diberikan. Dipakai untuk gerbang gabungan lintas-entitas seperti
     * impor/ekspor data master (yang menyentuh beberapa menu sekaligus),
     * bukan untuk pemeriksaan satu menu tunggal — untuk itu pakai
     * User::canAccessMenu().
     */
    public function canAccessAnyOf(array $keys): bool
    {
        return count(array_intersect($keys, $this->menu_keys ?? [])) > 0;
    }
}
