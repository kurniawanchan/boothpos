<?php

namespace App\Services;

use App\Exceptions\PinRejectedException;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * 048 / api-gap B1 — satu-satunya tempat yang memeriksa, mengganti, dan menghapus PIN.
 *
 * Aturan:
 *  - PIN = tepat 6 digit; PIN lemah (semua digit sama, urut naik/turun) ditolak saat DIATUR.
 *  - 5 PIN salah berturut-turut → akun terkunci PIN 5 menit (429). (073, keputusan pemilik: sebelumnya 15 menit; kunci yang
 *    sama menjaga PIN penyetuju dan ganti-PIN.) Penghitungan dan pengecekan kunci
 *    terjadi dalam SATU transaksi dengan `lockForUpdate`, supaya 100 tebakan paralel tidak lolos
 *    sebelum hitungan naik.
 *  - Kunci hanya berlaku untuk PIN; kata sandi tetap bisa dipakai masuk lewat /auth/login.
 *  - Pengguna yang tidak ada / tidak aktif / bukan staf outlet itu semua dijawab sama (401 generik, tanpa
 *    hitungan) — daftar staf memang sudah terbuka bagi terminal, tetapi alasan penolakan tidak dibocorkan.
 *  - Token hasil PIN bernama `pos-pin` dan kedaluwarsa (12 jam); token kata sandi lama tidak berubah.
 */
class PinAuthService
{
    public const MAX_ATTEMPTS = 5;
    public const LOCK_MINUTES = 5;
    public const TOKEN_NAME = 'pos-pin';
    public const TOKEN_HOURS = 12;

    public function __construct(private ActivityLogger $log) {}

    /**
     * Staf aktif yang boleh masuk di outlet ini (ditugaskan, atau peran semua-outlet).
     *
     * 073 — owner/admin TIDAK termasuk: layar pilih-staf kini terbuka tanpa login, jadi akun berhak tertinggi tidak boleh
     * terdaftar atau bisa masuk lewat PIN di tablet (keputusan pemilik, spec Q3). Ukurannya `isOwnerOrAdmin()` (menu
     * `settings`), definisi yang sama dengan seluruh aplikasi — bukan `all_outlets`, karena peran khusus bisa melihat semua
     * outlet tanpa menjadi admin. `login()` memakai daftar ini juga, jadi owner/admin ditolak dengan 401 generik tanpa hitungan.
     */
    public function roster(Outlet $outlet): Collection
    {
        return User::query()
            ->with('role')
            ->where('is_active', true)
            ->where(function ($q) use ($outlet) {
                $q->whereHas('outlets', fn ($o) => $o->where('outlets.id', $outlet->id))
                    ->orWhereHas('role', fn ($r) => $r->where('all_outlets', true));
            })
            ->orderBy('name')
            ->get()
            ->reject(fn (User $u) => $u->isOwnerOrAdmin())
            ->values();
    }

    /** @return array{user: User, token: string, expires_at: \Carbon\CarbonInterface} */
    public function login(Outlet $outlet, int $userId, #[\SensitiveParameter] string $pin, Device $device): array
    {
        $generic = fn () => new PinRejectedException(__('auth.invalid_pin'), 'invalid_pin', 401);

        $eligible = $this->roster($outlet)->firstWhere('id', $userId);
        if ($eligible === null) {
            throw $generic();
        }

        // Galat dikembalikan, BUKAN dilempar, dari dalam transaksi: melempar membatalkan transaksi dan hitungan
        // salah-PIN yang baru dinaikkan ikut hilang — kunci 5-salah tidak akan pernah tercapai.
        [$user, $error] = DB::transaction(function () use ($userId, $pin, $generic, $outlet, $device) {
            $user = User::lockForUpdate()->findOrFail($userId);
            $error = $this->verifyAndCount($user, $pin, $generic);

            // 073 / FR-015 — masuk dicatat dengan outlet dan terminal, di transaksi yang sama dengan pemeriksaannya.
            if ($error === null) {
                $this->log->log($user->id, 'pin_login', 'user', $user->id, "Masuk dengan PIN di terminal {$device->name} (outlet {$outlet->name})");
            }

            return [$user, $error];
        });

        if ($error !== null) {
            throw $error;
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $this->issueToken($user);
    }

    /**
     * 048 / S3 — PIN penyetuju untuk persetujuan di titik aksi. Memakai kunci 5-salah yang SAMA dengan login
     * (satu penghitung). Mengembalikan galat, bukan melempar, supaya hitungan salah tidak ikut batal dari
     * transaksi pemanggil.
     */
    public function verifyApproverPin(User $approver, #[\SensitiveParameter] string $pin): ?PinRejectedException
    {
        return $this->verifyAndCount($approver, $pin, fn () => new PinRejectedException(__('auth.invalid_pin'), 'invalid_pin', 401));
    }

    /** Ganti/atur PIN sendiri. Sudah punya PIN → `current_pin`; belum → `current_password`. */
    public function change(User $user, #[\SensitiveParameter] string $newPin, #[\SensitiveParameter] ?string $currentPin, #[\SensitiveParameter] ?string $currentPassword): void
    {
        $this->assertStrong($newPin);

        $error = DB::transaction(function () use ($user, $newPin, $currentPin, $currentPassword) {
            $locked = User::lockForUpdate()->findOrFail($user->id);

            if ($locked->pin_hash !== null) {
                if ($currentPin === null) {
                    return new PinRejectedException(__('auth.current_pin_required'), 'current_pin_required', 422);
                }
                // Salah-PIN saat mengganti ikut dihitung: ini jalur tebak PIN yang sama.
                $error = $this->verifyAndCount($locked, $currentPin, fn () => new PinRejectedException(__('auth.invalid_pin'), 'invalid_pin', 401));
                if ($error !== null) {
                    return $error;
                }
            } elseif ($currentPassword === null || ! Hash::check($currentPassword, $locked->password)) {
                return new PinRejectedException(__('auth.current_password_incorrect'), 'invalid_password', 401);
            }

            $locked->forceFill([
                'pin_hash' => Hash::make($newPin),
                'pin_failed_attempts' => 0,
                'pin_locked_until' => null,
                'pin_changed_at' => now(),
            ])->save();

            $this->revokePinTokens($locked, exceptTokenId: $user->currentAccessToken()?->id);
            $this->log->log($user->id, 'pin_changed', 'user', $locked->id, 'PIN diganti');

            return null;
        });

        if ($error !== null) {
            throw $error;
        }
    }

    /** Admin menghapus PIN dan kunci seseorang; yang bersangkutan mengatur PIN baru dengan kata sandinya. */
    public function reset(User $actor, User $target): void
    {
        DB::transaction(function () use ($actor, $target) {
            $target->forceFill([
                'pin_hash' => null, 'pin_failed_attempts' => 0, 'pin_locked_until' => null, 'pin_changed_at' => null,
            ])->save();
            $this->revokePinTokens($target);
            $this->log->log($actor->id, 'pin_reset', 'user', $target->id, 'PIN direset oleh admin');
        });
    }

    /**
     * 074 — owner/admin MENGATUR PIN pengguna lain (atau dirinya) dari layar Pengguna: pertama kali atau mengganti. Satu langkah,
     * tanpa kata sandi pengguna (beda dengan `change()`). Mengembalikan true bila pengguna SUDAH punya PIN (diganti).
     *
     * Efek (satu transaksi, baris pengguna dikunci supaya dua admin serentak berakhir dengan satu PIN — yang terakhir menang):
     * kunci dan hitungan salah dibersihkan, sesi PIN pengguna di tablet berakhir, dan satu catatan aktivitas ditulis TANPA PIN
     * maupun hash. `$source` (typed|generated) dilaporkan klien dan tidak bisa dibuktikan server, jadi hanya penanda di log.
     */
    public function adminSet(User $actor, User $target, #[\SensitiveParameter] string $pin, string $source): bool
    {
        $this->assertStrong($pin);

        return DB::transaction(function () use ($actor, $target, $pin, $source) {
            $locked = User::lockForUpdate()->findOrFail($target->id);
            $replaced = $locked->pin_hash !== null;

            $locked->forceFill([
                'pin_hash' => Hash::make($pin),
                'pin_failed_attempts' => 0,
                'pin_locked_until' => null,
                'pin_changed_at' => now(),
            ])->save();
            $this->revokePinTokens($locked);

            $sourceText = $source === 'generated' ? 'dibuat sistem' : 'diketik admin';
            $this->log->log(
                $actor->id,
                $replaced ? 'pin_replaced' : 'pin_set',
                'user',
                $locked->id,
                ($replaced ? 'PIN diganti' : 'PIN diatur')." untuk {$locked->name} oleh admin (sumber: {$sourceText}, dilaporkan klien)",
                null,
                ['source' => $source],
            );

            return $replaced;
        });
    }

    /**
     * 074 — saran PIN acak 6 digit untuk tombol "Generate". `random_int` (CSPRNG) diulang sampai lolos aturan PIN lemah yang sama
     * dengan penolakan. TIDAK disimpan dan tidak terikat pada pengguna: baru tersimpan bila admin menyimpannya lewat adminSet().
     */
    public function suggest(): string
    {
        do {
            $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while ($this->isWeak($pin));

        return $pin;
    }

    /** @return array{user: User, token: string, expires_at: \Carbon\CarbonInterface} */
    public function refresh(User $user): array
    {
        $current = $user->currentAccessToken();

        // Hanya token PIN yang boleh diperpanjang; token kata sandi tidak kedaluwarsa dan tak perlu disegarkan.
        if ($current === null || $current->name !== self::TOKEN_NAME) {
            throw new PinRejectedException(__('auth.token_not_refreshable'), 'token_not_refreshable', 403);
        }
        if (! $user->is_active) {
            throw new PinRejectedException(__('auth.inactive_account'), 'inactive_account', 401);
        }

        $issued = $this->issueToken($user);
        $current->delete();

        return $issued;
    }

    /**
     * Mengembalikan galat (null = PIN benar). Efek sampingnya (hitungan, kunci, log) ditulis di transaksi
     * pemanggil, yang HARUS commit sebelum galat dilempar.
     */
    private function verifyAndCount(User $user, #[\SensitiveParameter] string $pin, callable $generic): ?PinRejectedException
    {
        if ($user->pin_locked_until !== null && $user->pin_locked_until->isFuture()) {
            return $this->locked($user);
        }

        if ($user->pin_hash === null) {
            // Tidak ada yang bisa ditebak: jawab generik tanpa menghitung.
            return $generic();
        }

        if (Hash::check($pin, $user->pin_hash)) {
            if ($user->pin_failed_attempts > 0 || $user->pin_locked_until !== null) {
                $user->forceFill(['pin_failed_attempts' => 0, 'pin_locked_until' => null])->save();
            }

            return null;
        }

        $attempts = $user->pin_failed_attempts + 1;
        if ($attempts >= self::MAX_ATTEMPTS) {
            $user->forceFill(['pin_failed_attempts' => 0, 'pin_locked_until' => now()->addMinutes(self::LOCK_MINUTES)])->save();
            $this->log->log($user->id, 'pin_locked', 'user', $user->id, 'PIN terkunci setelah salah berturut-turut');

            return $this->locked($user);
        }

        $user->forceFill(['pin_failed_attempts' => $attempts])->save();

        return new PinRejectedException(__('auth.invalid_pin'), 'invalid_pin', 401, [
            'attempts_remaining' => self::MAX_ATTEMPTS - $attempts,
        ]);
    }

    private function locked(User $user): PinRejectedException
    {
        return new PinRejectedException(__('auth.pin_locked'), 'pin_locked', 429, [
            'locked_until' => $user->pin_locked_until?->toIso8601String(),
        ]);
    }

    private function assertStrong(#[\SensitiveParameter] string $pin): void
    {
        if ($this->isWeak($pin)) {
            throw new PinRejectedException(__('auth.pin_too_weak'), 'pin_too_weak', 422);
        }
    }

    /** SATU definisi PIN lemah (semua digit sama, atau urut naik/turun penuh); dipakai penolakan DAN pembuat saran PIN. */
    private function isWeak(#[\SensitiveParameter] string $pin): bool
    {
        $digits = array_map('intval', str_split($pin));
        $same = count(array_unique($digits)) === 1;
        $asc = $pin === implode('', range($digits[0], $digits[0] + 5)) && $digits[0] <= 4;
        $desc = $pin === implode('', range($digits[0], $digits[0] - 5)) && $digits[0] >= 5;

        return $same || $asc || $desc;
    }

    /** @return array{user: User, token: string, expires_at: \Carbon\CarbonInterface} */
    private function issueToken(User $user): array
    {
        $expires = now()->addHours(self::TOKEN_HOURS);

        return [
            'user' => $user,
            'token' => $user->createToken(self::TOKEN_NAME, ['*'], $expires)->plainTextToken,
            'expires_at' => $expires,
        ];
    }

    private function revokePinTokens(User $user, ?int $exceptTokenId = null): void
    {
        $user->tokens()->where('name', self::TOKEN_NAME)
            ->when($exceptTokenId, fn ($q) => $q->where('id', '!=', $exceptTokenId))
            ->delete();
    }
}
