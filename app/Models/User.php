<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'username',
        'password',
        'role_id',
        'photo_path',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Satu-satunya primitif otorisasi berbasis menu di seluruh kodebase
     * ini (Constitution Principle I) — setiap FormRequest/Policy/inline
     * controller check yang butuh menjawab "boleh akses X" WAJIB
     * memanggil ini, bukan menguji ulang $this->role->menu_keys sendiri.
     *
     * Tidak menambah query per pemanggilan: $this->role di-load sekali per
     * request (relasi Eloquent standar, di-cache pada instance setelah
     * akses pertama).
     */
    public function canAccessMenu(string $menuKey): bool
    {
        return in_array($menuKey, $this->role?->menu_keys ?? [], true);
    }

    /**
     * Sama seperti canAccessMenu() tapi untuk gerbang gabungan yang
     * menyentuh beberapa menu sekaligus (mis. impor/ekspor data master
     * lintas entitas) — delegasi ke Role::canAccessAnyOf() supaya tidak
     * ada jalur kedua yang membaca menu_keys secara independen.
     */
    public function canAccessAnyMenu(array $menuKeys): bool
    {
        return $this->role?->canAccessAnyOf($menuKeys) ?? false;
    }

    /**
     * DEPRECATED — dipertahankan HANYA sebagai wrapper tipis di atas
     * canAccessMenu('settings') untuk dua call site yang sengaja TIDAK
     * dimigrasikan pada Fase 2 (001-user-store-settings):
     * CashierSessionController::close()/summary() (gerbangnya gabungan
     * kepemilikan-sesi ATAU privileged, bukan otorisasi menu murni — di
     * luar cakupan T012) dan PaymentProofController::show() (gerbangnya
     * gabungan pemilik-bukti ATAU privileged, pola identik). Jangan
     * memakai method ini di kode baru — panggil canAccessMenu('settings')
     * langsung, atau lebih baik lagi, cari primitif yang lebih tepat kalau
     * konteksnya bukan sungguh-sungguh "setara owner/admin hari ini".
     */
    public function isOwnerOrAdmin(): bool
    {
        return $this->canAccessMenu('settings');
    }
}
