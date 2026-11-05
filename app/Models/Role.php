<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Support\MenuActions;
use App\Support\MenuKeys;

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
        'menu_actions',
        'is_system_default',
    ];

    protected function casts(): array
    {
        return [
            'menu_keys' => 'array',
            'menu_actions' => 'array',
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

    /** 057 — peran Owner bawaan selalu akses penuh, apa pun isi `menu_actions` (tak ada yang bisa mengunci instalasi). */
    public function isOwner(): bool
    {
        return $this->is_system_default && strcasecmp((string) $this->name, 'Owner') === 0;
    }

    /**
     * 057 — SATU-SATUNYA definisi izin aksi. View = punya menunya. Aksi lain: NULL `menu_actions` atau menu tanpa
     * entri = akses penuh (perilaku lama, spec FR-006/007); entri `[]` = hanya lihat; entri berisi = persis itu.
     * Aksi tak dikenal di data tersimpan diabaikan, tak pernah diberikan.
     */
    public function allows(string $menu, string $action = MenuActions::VIEW): bool
    {
        if (! in_array($menu, $this->menu_keys ?? [], true)) {
            return false;
        }
        if ($action === MenuActions::VIEW) {
            return true;
        }
        if (! in_array($action, MenuActions::for($menu), true)) {
            return false;
        }
        if ($this->isOwner()) {
            return true;
        }
        $grants = $this->menu_actions;
        if ($grants === null || ! array_key_exists($menu, $grants)) {
            return true;
        }

        return in_array($action, (array) $grants[$menu], true);
    }

    /** 057 — menu ⇒ aksi yang diizinkan (View lebih dulu); dipakai GET /auth/me dan RoleResource. */
    public function resolvedActions(): array
    {
        $out = [];
        foreach ($this->menu_keys ?? [] as $menu) {
            if (! MenuKeys::isValid($menu)) {
                continue;
            }
            $out[$menu] = array_values(array_filter(
                MenuActions::withView($menu),
                fn (string $a) => $this->allows($menu, $a),
            ));
        }

        return $out;
    }

    /** 057 — akses PENUH (semua aksi) pada setiap menu yang diberikan; dasar guard lock-out dan penanda "aksi khusus". */
    public function hasFullAccessTo(array $menus): bool
    {
        foreach ($menus as $menu) {
            foreach (MenuActions::withView($menu) as $action) {
                if (! $this->allows($menu, $action)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** 057 — true bila ada pembatasan aksi pada salah satu menu yang dimiliki (untuk penanda di daftar peran). */
    public function hasCustomActions(): bool
    {
        return ! $this->hasFullAccessTo(array_values(array_filter($this->menu_keys ?? [], fn ($m) => MenuKeys::isValid($m))));
    }
}
