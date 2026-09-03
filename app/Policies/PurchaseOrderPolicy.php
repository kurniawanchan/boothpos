<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;

class PurchaseOrderPolicy
{
    /**
     * Sengaja BEDA dari VendorPolicy/MaterialPolicy (yang membiarkan
     * viewAny/view terbuka untuk siapa pun sudah login) — baris Purchase
     * Order membawa harga beli aktual per transaksi, bukan sekadar harga
     * acuan vendor, jadi baca pun digerbang canAccessMenu('purchase_orders')
     * yang sama dengan tulis.
     */
    public function viewAny(User $user): bool
    {
        return $user->canAccessMenu('purchase_orders');
    }

    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->canAccessMenu('purchase_orders');
    }

    public function create(User $user): bool
    {
        return $user->canAccessMenu('purchase_orders');
    }

    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->canAccessMenu('purchase_orders');
    }

    public function delete(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->canAccessMenu('purchase_orders');
    }
}
