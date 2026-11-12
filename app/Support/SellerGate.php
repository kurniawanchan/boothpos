<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;

/**
 * 058 — SATU-satunya tempat yang tahu "pengguna ini dibatasi ke seller mana". Dipakai global scope
 * (SellerScope) DAN query mentah laporan (`DB::table`, yang tidak kena scope Eloquent) — predikat yang sama
 * dari satu sumber, persis seperti ModeGate untuk DEMO/LIVE (Constitution I).
 *
 * `current()` null berarti TANPA batasan: pengguna tanpa seller, serta konteks tanpa pengguna (CLI, antrean,
 * seeder, kloner DEMO). Jangan pernah "gagal terbuka" dengan cara lain: seller non-aktif tetap mengembalikan id-nya
 * sehingga tampilannya kosong, bukan menjadi tampilan penuh.
 */
class SellerGate
{
    public static function current(): ?int
    {
        $user = auth()->user();

        // Customer commerce — pengguna guard `customer` adalah App\Models\CustomerAccount, BUKAN staf: ia tak punya
        // seller maupun peran, jadi tidak ada batasan seller untuknya (visibilitas etalase diatur scope-nya sendiri).
        // Tanpa pengecekan ini setiap query model ber-SellerScope di rute pelanggan berakhir TypeError (500). Aman
        // hanya karena config/auth.php mengikat guard staf ke provider `users`: subjek non-User tidak pernah sampai
        // ke rute staf. forUser() sengaja tetap bertipe ketat.
        return $user instanceof \App\Models\User ? self::forUser($user) : null;
    }

    /**
     * Seller yang membatasi PENGGUNA tertentu (dipakai juga untuk payload login, saat belum ada sesi guard).
     * Owner TIDAK PERNAH dibatasi (keputusan product owner 2026-11-05, research D11), walau artist_id basi
     * tersisa. Pengecekan ini yang menjamin; validasi di UserController hanya menjaga data tetap bersih.
     */
    public static function forUser(?\App\Models\User $user): ?int
    {
        $id = $user?->artist_id;

        if ($id === null || $user->role?->isOwner()) {
            return null;
        }

        return (int) $id;
    }

    public static function active(): bool
    {
        return self::current() !== null;
    }

    /**
     * Batasi query baris-item (kolom seller langsung, mis. `order_items.artist_id`) ke seller pengguna.
     * Tak berbuat apa-apa bila pengguna tanpa seller. Untuk query mentah yang melewati scope Eloquent.
     *
     * @param  BuilderContract|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     */
    public static function applyToItems($query, string $column): void
    {
        $id = self::current();
        if ($id !== null) {
            $query->where($column, $id);
        }
    }

    /**
     * Batasi query tingkat HEADER (orders.id / preorders.id) ke order yang punya baris seller pengguna.
     * Untuk query mentah yang membaca tabel header langsung (mis. laporan pre-order).
     */
    public static function applyToOrderHeaders($query, string $column = 'orders.id'): void
    {
        $id = self::current();
        if ($id !== null) {
            $query->whereIn($column, self::orderIdsSubquery($id));
        }
    }

    public static function applyToPreorderHeaders($query, string $column = 'preorders.id'): void
    {
        $id = self::current();
        if ($id !== null) {
            $query->whereIn($column, self::preorderIdsSubquery($id));
        }
    }

    /** Id order yang punya minimal satu baris milik seller pengguna (subquery, untuk whereIn). */
    public static function orderIdsSubquery(int $sellerId): \Closure
    {
        return fn ($q) => $q->select('order_id')->from('order_items')->where('artist_id', $sellerId);
    }

    public static function preorderIdsSubquery(int $sellerId): \Closure
    {
        return fn ($q) => $q->select('preorder_id')->from('preorder_items')->where('artist_id', $sellerId);
    }

    /** Id produk seller — TERMASUK yang sudah dihapus lunak, supaya riwayat stok/BOM-nya tetap terlihat. */
    public static function productIdsSubquery(int $sellerId): \Closure
    {
        return fn ($q) => $q->select('id')->from('products')->where('artist_id', $sellerId);
    }
}
