<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Support\MenuKeys;

/**
 * Gerbang menu 'roles' (403) untuk seluruh operasi CRUD. Dua guard bisnis
 * FR-013/FR-014 SENGAJA bukan bagian dari method boolean standar di sini —
 * keduanya bukan pertanyaan hak akses ("apakah peran saya boleh?")
 * melainkan konflik aturan bisnis ("apakah perubahan ini akan membuat toko
 * kehilangan kemampuan mengelola aksesnya sendiri, atau peran ini masih
 * dipakai?"), jadi jawabannya harus 409 bukan 403 — pola yang sama dengan
 * UserPolicy::isSelfLockout() untuk FR-006. RoleController memanggil kedua
 * primitif ini secara manual dan menjawab 409 sendiri.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessMenu('roles');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->canAccessMenu('roles');
    }

    public function create(User $user): bool
    {
        return $user->canAccessMenu('roles');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->canAccessMenu('roles');
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->canAccessMenu('roles');
    }

    /**
     * FR-014 — peran yang masih dipakai satu atau lebih akun pengguna
     * AKTIF tidak boleh dihapus. Akun nonaktif/soft-deleted tidak dihitung
     * (riwayat lama boleh tetap merujuk peran yang sudah tiada, konsisten
     * dengan pola soft-delete master data lain di kodebase ini).
     */
    public function hasActiveUsers(Role $role): bool
    {
        return $role->users()->where('is_active', true)->exists();
    }

    /**
     * FR-013 — mencegah perubahan (update `menu_keys`) ATAU penghapusan
     * yang akan membuat TIDAK ADA satu pun peran tersisa yang mencakup
     * KEDUA kunci reserved (`users` DAN `roles`). Pemeriksaan ini SELALU
     * menghitung peran LAIN (bukan $role yang sedang diubah/dihapus) —
     * peran yang sedang diproses dianggap sudah tidak lagi punya akses
     * itu, baik karena dihapus maupun karena menu_keys barunya tidak lagi
     * mencakup keduanya.
     *
     * $excludeRoleId dikecualikan dari pencarian, bukan diasumsikan dari
     * $role->id, supaya method ini juga benar dipakai untuk kasus "role
     * baru saja soft-deleted" di mana $role->id tetap ada di DB tapi harus
     * tidak dihitung.
     */
    public function wouldLeaveNoRoleCapableOfManagingAccess(int $excludeRoleId): bool
    {
        $reserved = MenuKeys::RESERVED;

        $anotherCapableRoleExists = Role::query()
            ->where('id', '!=', $excludeRoleId)
            ->get()
            ->contains(fn (Role $candidate) => count(array_intersect($reserved, $candidate->menu_keys ?? [])) === count($reserved));

        return ! $anotherCapableRoleExists;
    }
}
