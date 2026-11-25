<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * 094 / research D4 — SATU-SATUNYA pemeriksa username + kata sandi dan jatah percobaannya, dipakai login web
 * (`POST /auth/login`) DAN login aplikasi manajemen (`POST /auth/manager-sessions`). Dua jalur login berbagi hitungan yang
 * sama, jadi penebak tidak mendapat jatah dua kali lipat dengan berganti endpoint. Dipindah apa adanya dari
 * AuthController::login (078); perilaku web tidak berubah.
 */
class CredentialLogin
{
    /** 078 — percobaan login gagal yang diizinkan per akun+IP dalam satu jendela. */
    private const MAX_ATTEMPTS = 5;

    /** 078 — kegagalan per akun dari semua IP (sengaja jauh di atas 5: lihat komentar di attempt()). */
    private const MAX_FAILURES_PER_ACCOUNT = 30;

    private const DECAY_SECONDS = 60;

    /**
     * Mengembalikan pengguna aktif yang kredensialnya benar, atau melempar respons 401 (kredensial salah / akun nonaktif)
     * atau 429 (jatah habis). Hitungan dibersihkan HANYA bila kredensial benar dan akun aktif.
     */
    public function attempt(string $username, string $password, string $ip): User
    {
        // 078 — jatah percobaan login. Kunci memakai IDENTITAS yang benar-benar dikenali DB, bukan string yang dikirim: pengguna dicari
        // dulu dan kuncinya `id:N`, sehingga varian huruf/aksen/spasi yang dicocokkan collation DB jatuh ke hitungan yang sama
        // (dulu tiap varian punya hitungan sendiri dan melewati batas). Nama yang tidak ada dinormalkan (trim, huruf kecil, tanpa
        // aksen) supaya variannya juga menyatu, dan tetap dihitung seperti akun nyata (tanpa enumerasi).
        //  (1) akun+IP: 5 per menit. `hit` DULU lalu bandingkan (increment atomik; "cek lalu hit" membiarkan permintaan paralel lolos).
        //  (2) username dari SEMUA IP: 30 per menit, untuk tebakan yang disebar lintas alamat. Slot dipesan atomik setelah lolos (1) dan
        //      dihapus bila login berhasil (jadi yang terhitung = kegagalan); permintaan yang sudah ditolak oleh (1) tidak menyentuhnya — satu sumber tidak bisa
        //      mengisi hitungan akun dan mengunci pemiliknya dari mana pun. (Botnet lintas banyak IP tetap bisa mencapai 30; itu
        //      kompromi yang diterima, dicatat di specs/078-.../evidence.md.)
        // Login yang berhasil menghapus kedua hitungan.
        $user = User::where('username', $username)->first();
        $identity = $user ? 'id:'.$user->id : 'name:'.Str::of($username)->trim()->lower()->ascii();
        $key = 'login|'.$identity.'|'.$ip;
        $userKey = 'login-user|'.$identity;
        if (RateLimiter::hit($key, self::DECAY_SECONDS) > self::MAX_ATTEMPTS) {
            throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => max(1, RateLimiter::availableIn($key))]);
        }
        // Slot akun dipesan secara ATOMIK (`hit` lalu bandingkan) hanya oleh permintaan yang lolos batas per-IP di atas — permintaan yang
        // sudah ditolak tidak menyentuhnya. Login yang berhasil menghapusnya, jadi yang tersisa hanyalah kegagalan.
        if (RateLimiter::hit($userKey, self::DECAY_SECONDS) > self::MAX_FAILURES_PER_ACCOUNT) {
            throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => max(1, RateLimiter::availableIn($userKey))]);
        }

        // Pesan error disamakan untuk "user tidak ada" dan "password salah"
        // agar tidak membocorkan username mana yang valid (OWASP —
        // Improper Error Handling / user enumeration).
        if (! $user || ! Hash::check($password, $user->password)) {
            throw new HttpResponseException(response()->json(['message' => __('auth.invalid_credentials')], 401));
        }

        if (! $user->is_active) {
            throw new HttpResponseException(response()->json(['message' => __('auth.inactive_account')], 401));
        }

        RateLimiter::clear($key);
        RateLimiter::clear($userKey);

        return $user;
    }
}
