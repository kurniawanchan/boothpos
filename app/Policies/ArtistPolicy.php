<?php

namespace App\Policies;

use App\Models\Artist;
use App\Models\User;
use App\Support\LicenseGate;
use Illuminate\Auth\Access\Response;

class ArtistPolicy
{
    /**
     * Seluruh peran boleh melihat daftar dan detail artist — kasir perlu
     * konteks ini saat menjelaskan produk ke pembeli.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Artist $artist): bool
    {
        return true;
    }

    /**
     * Dua lapis pemeriksaan: peran (canManageMasterData) DAN kuota
     * lisensi (LicenseGate). Kuota dicek di sini, bukan di controller,
     * supaya satu-satunya jalur otorisasi Artist tetap policy ini — pola
     * yang sama dipakai seluruh modul lain di kodebase ini.
     */
    public function create(User $user): Response
    {
        if (! $user->canAccessMenu('artists')) {
            return Response::deny('Anda tidak berhak mengelola artist.');
        }

        if (! LicenseGate::canCreateArtist()) {
            return Response::deny(
                'Instalasi ini memakai lisensi Pro (satu artist). Upgrade ke Master untuk menambah artist lain.'
            );
        }

        return Response::allow();
    }

    public function update(User $user, Artist $artist): bool
    {
        return $user->canAccessMenu('artists');
    }

    public function delete(User $user, Artist $artist): bool
    {
        return $user->canAccessMenu('artists');
    }
}
