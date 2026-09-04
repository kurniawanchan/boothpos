<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    // Seluruh peran yang login (termasuk cashier) boleh kelola pelanggan —
    // dibutuhkan saat kasir mencatat pre-order di lapangan.
    public function viewAny(User $user): bool { return true; }
    public function view(User $user, Customer $customer): bool { return true; }
    public function create(User $user): bool { return true; }
    public function update(User $user, Customer $customer): bool { return true; }

    // Hapus permanen dibatasi owner/admin saja, berbeda dari CRUD lain di
    // atas — mengikuti pola guard hapus Artist/Category (lihat plan
    // 009-ui-ux-refinements T004).
    public function delete(User $user, Customer $customer): bool { return $user->isOwnerOrAdmin(); }
}
