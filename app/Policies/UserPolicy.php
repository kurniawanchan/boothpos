<?php

namespace App\Policies;

use App\Models\User;

/**
 * Gerbang menu 'users' (403) untuk seluruh operasi. Guard swa-kunci
 * (FR-006) sengaja BUKAN bagian dari method boolean di sini — itu bukan
 * pertanyaan hak akses ("apakah role saya boleh?") melainkan konflik
 * aturan bisnis ("apakah tindakan ini terhadap akun saya sendiri?"), jadi
 * jawabannya harus 409 bukan 403. isSelfLockout() disediakan sebagai
 * primitif yang dipanggil manual oleh UserController, meniru pola guard
 * bisnis 409 lain di kodebase ini (mis. ArtistController::destroy()'s
 * cek produk aktif).
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessMenu('users');
    }

    public function view(User $user, User $target): bool
    {
        return $user->canAccessMenu('users');
    }

    public function create(User $user): bool
    {
        return $user->canAccessMenu('users');
    }

    public function update(User $user, User $target): bool
    {
        return $user->canAccessMenu('users');
    }

    public function delete(User $user, User $target): bool
    {
        return $user->canAccessMenu('users');
    }

    /**
     * FR-006 — seorang pengguna tidak boleh menonaktifkan, menghapus, atau
     * mengganti peran akun yang sedang dipakainya untuk login sekarang.
     * $deactivating/$roleChanging harus dihitung dari data tervalidasi,
     * bukan dari flag yang dikirim klien.
     */
    public function isSelfLockout(User $actor, User $target, bool $deactivating, bool $roleChanging): bool
    {
        return $actor->id === $target->id && ($deactivating || $roleChanging);
    }
}
