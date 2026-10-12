<?php

namespace App\Policies;

use App\Models\Outlet;
use App\Models\User;
use App\Support\LicenseGate;
use Illuminate\Auth\Access\Response;

class OutletPolicy
{
    /**
     * Seluruh peran boleh melihat daftar dan detail artist — kasir perlu
     * konteks ini saat menjelaskan produk ke pembeli.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Outlet $outlet): bool
    {
        return true;
    }

    /**
     * Dua lapis pemeriksaan: peran (canManageMasterData) DAN kuota
     * lisensi (LicenseGate). Kuota dicek di sini, bukan di controller,
     * supaya satu-satunya jalur otorisasi Outlet tetap policy ini — pola
     * yang sama dipakai seluruh modul lain di kodebase ini.
     */
    public function create(User $user): Response
    {
        if (! $user->canAccessMenu('outlets')) {
            return Response::deny(__('master_data.outlet_not_authorized'));
        }

        if (! LicenseGate::canCreateOutlet()) {
            return Response::deny(__('master_data.outlet_license_quota_exceeded'));
        }

        return Response::allow();
    }

    public function update(User $user, Outlet $outlet): bool
    {
        return $user->canAccessMenu('outlets');
    }

    public function delete(User $user, Outlet $outlet): bool
    {
        return $user->canAccessMenu('outlets');
    }
}
