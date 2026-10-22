<?php

namespace App\Support;

use App\Models\CashierSession;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * 065 / research D4 — SUMBER sebuah permintaan: aplikasi tablet (membawa `X-Device-Id`) atau web (tanpa header).
 * Shift aplikasi dan shift web dipisah: satu pengguna boleh memegang satu shift terbuka dari masing-masing sumber,
 * dan pembayaran/refund/kas keluar masuk ke shift SUMBERNYA — tidak pernah ke shift sumber lain.
 *
 * Sumber diturunkan server dari header yang sudah dipakai `CashierSessionController::store()` untuk menstempel
 * `cashier_sessions.device_id`; tidak pernah dari body (Konstitusi IV). Header hanya MEMILIH di antara shift milik
 * pengguna itu sendiri — id sembarang paling banter tidak menemukan apa-apa. Validasi terminal aktif tetap di
 * `store()` (422 `device_id`), karena di situlah sebuah shift dibuat.
 *
 * SATU-SATUNYA tempat "shift terbuka milik pengguna" dicari: `current()`, `store()`, `PaymentService`,
 * `RefundService` memakainya, supaya aturan sumber tidak bisa melenceng antar jalur.
 */
final class ShiftSource
{
    /** Permintaan datang dari tablet bila membawa X-Device-Id (diisi klien setelah terminal dipasang). */
    public static function isApp(?Request $request = null): bool
    {
        $request ??= request();

        return ! empty($request->header('X-Device-Id'));
    }

    /** Shift terbuka milik [user] dari sumber permintaan ini; null bila sumber itu tidak punya. */
    public static function openFor(User $user, ?Request $request = null, bool $lock = false): ?CashierSession
    {
        return self::openQuery($user, self::isApp($request))->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    /** Apakah [user] sudah punya shift terbuka dari sumber [app] (true) / web (false) — dipakai saat membuka shift. */
    public static function hasOpen(User $user, bool $app, bool $lock = false): bool
    {
        return self::openQuery($user, $app)->when($lock, fn ($q) => $q->lockForUpdate())->exists();
    }

    private static function openQuery(User $user, bool $app)
    {
        $query = CashierSession::query()->where('user_id', $user->id)->where('status', 'open');

        return $app ? $query->whereNotNull('device_id') : $query->whereNull('device_id');
    }
}
