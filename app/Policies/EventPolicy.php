<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    public function viewAny(User $user): bool { return true; }
    public function view(User $user, Event $event): bool { return true; }

    // Event bukan bagian dari "master data" biasa (bukan produk/stok),
    // jadi sengaja TIDAK memakai canManageMasterData() — hanya owner/admin,
    // peran 'inventory' tidak termasuk. Sejalan dengan tabel peran PRD
    // bagian 5, yang tidak mencantumkan event di akses Manajer Inventori.
    public function create(User $user): bool { return $user->isOwnerOrAdmin(); }
    public function update(User $user, Event $event): bool { return $user->isOwnerOrAdmin(); }
    public function transitionStatus(User $user, Event $event): bool { return $user->isOwnerOrAdmin(); }
}
