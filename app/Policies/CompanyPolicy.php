<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessMenu('companies');
    }

    public function view(User $user, Company $company): bool
    {
        return $user->canAccessMenu('companies');
    }

    public function create(User $user): bool
    {
        return $user->canAccessMenu('companies');
    }

    public function activate(User $user, Company $company): bool
    {
        return $user->canAccessMenu('companies');
    }
}
