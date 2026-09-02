<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    public function viewAny(User $user): bool { return true; }
    public function view(User $user, Event $event): bool { return true; }

    // Event bukan bagian dari "master data" biasa (bukan produk/stok),
    // jadi sengaja TIDAK memakai canAccessMenu('products'/dst) — hanya
    // owner/admin, peran 'inventory' tidak termasuk. Sejalan dengan tabel
    // peran PRD bagian 5, yang tidak mencantumkan event di akses Manajer
    // Inventori.
    //
    // CATATAN PENTING (001-user-store-settings, Fase 2): tidak ada kunci
    // menu tersendiri untuk "kelola event" — menu 'events' sendiri tampil
    // untuk SEMUA peran (kasir perlu melihat event untuk konteks
    // transaksi), sementara create/update/transitionStatus tetap harus
    // owner/admin saja. Ini murni gerbang PER-AKSI di dalam satu menu yang
    // dibagi bersama, di luar cakupan model "akses menu" fitur ini (lihat
    // spec.md Assumptions: "menu-level access only, tidak mencakup
    // permission per-aksi/per-verb CRUD"). Dipetakan ke canAccessMenu
    // ('settings') karena itu satu-satunya kunci menu yang, pada keempat
    // peran default hasil migrasi, persis berisi himpunan peran yang sama
    // (owner+admin) — BUKAN klaim bahwa event adalah bagian dari menu
    // Pengaturan. Risiko yang disadari: sebuah peran kustom yang diberi
    // akses 'settings' tanpa maksud mengelola event akan ikut bisa
    // membuat/mengubah event. Didokumentasikan sebagai batasan yang
    // disengaja di laporan implementasi fitur ini, bukan bug.
    public function create(User $user): bool { return $user->canAccessMenu('settings'); }
    public function update(User $user, Event $event): bool { return $user->canAccessMenu('settings'); }
    public function transitionStatus(User $user, Event $event): bool { return $user->canAccessMenu('settings'); }
}
