<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vendor;

class VendorPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Vendor $vendor): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->canAccessMenu('vendors');
    }

    public function update(User $user, Vendor $vendor): bool
    {
        return $user->canAccessMenu('vendors');
    }

    public function delete(User $user, Vendor $vendor): bool
    {
        return $user->canAccessMenu('vendors');
    }
}
