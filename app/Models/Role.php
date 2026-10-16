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
        'permissions',
        'discount_limit_pct',
        'is_system_default',
        'all_outlets',
    ];

    protected function casts(): array
    {
        return [
            'menu_keys' => 'array',
            'permissions' => 'array',
            'discount_limit_pct' => 'decimal:2',
            'is_system_default' => 'boolean',
            // 045 Stage 2 — Owner/Admin melihat semua outlet aktif tanpa baris penugasan (D6).
            'all_outlets' => 'boolean',
        ];
    }

    /**
     * 043 — izin tingkat-aksi (App\Support\PermissionCatalog). `permissions` NULL = bawaan menurut nama
     * peran sistem; nilai tersimpan (termasuk daftar kosong) dihormati apa adanya.
     */
    public function allows(string $permission): bool
    {
        $granted = $this->permissions ?? \App\Support\PermissionCatalog::defaultsFor((string) $this->name);

        return in_array($permission, $granted, true);
    }

    /**
     * 048 / T058 — batas diskon (persen) yang boleh diberikan peran ini tanpa persetujuan. Kolom NULL = bawaan:
     * pemilik/admin tanpa batas (ASUMSI, 100 %); peran lain 0 (setiap diskon butuh persetujuan).
     */
    public function discountLimit(): float
    {
        if ($this->discount_limit_pct !== null) {
            return (float) $this->discount_limit_pct;
        }

        return in_array(mb_strtolower(trim((string) $this->name)), ['owner', 'admin'], true) ? 100.0 : 0.0;
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
