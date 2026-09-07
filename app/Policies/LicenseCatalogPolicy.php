<?php

namespace App\Policies;

use App\Models\License;
use App\Models\User;

/**
 * 019-billing-system — rename dari PackagePolicy, digeser ke menu key
 * `licenses` miliknya sendiri (research.md R2') — sebelumnya menumpang
 * di menu `companies`. Nama class sengaja LicenseCatalogPolicy, bukan
 * LicensePolicy, mengikuti penamaan controller-nya (R0) meski di sini
 * tidak ada risiko tabrakan nama secara langsung.
 */
class LicenseCatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessMenu('licenses');
    }

    public function view(User $user, License $license): bool
    {
        return $user->canAccessMenu('licenses');
    }

    public function create(User $user): bool
    {
        return $user->canAccessMenu('licenses');
    }

    public function update(User $user, License $license): bool
    {
        return $user->canAccessMenu('licenses');
    }

    public function delete(User $user, License $license): bool
    {
        return $user->canAccessMenu('licenses');
    }
}
