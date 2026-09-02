<?php

namespace App\Policies;

use App\Models\User;

/**
 * Setting bukan "master data" (produk/stok) — sengaja TIDAK memakai
 * canAccessMenu('products'/dst) (yang mencakup peran inventory), sama
 * seperti EventPolicy. Konfigurasi toko, format struk, dan flag lisensi
 * (multi_artist_enabled) adalah keputusan tingkat owner/admin, digerbang
 * canAccessMenu('settings').
 */
class SettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessMenu('settings');
    }

    public function update(User $user): bool
    {
        return $user->canAccessMenu('settings');
    }
}
