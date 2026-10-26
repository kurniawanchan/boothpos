<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\UpdateLanguageRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Models\User;
use App\Services\ImageUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    /** 078 — percobaan login gagal yang diizinkan per akun+IP dalam satu jendela. */
    private const LOGIN_MAX_ATTEMPTS = 5;

    /** 078 — batas per username dari semua IP (sengaja jauh di atas 5: lihat komentar di login()). */
    private const LOGIN_MAX_ATTEMPTS_PER_ACCOUNT = 30;

    private const LOGIN_DECAY_SECONDS = 60;

    public function __construct(private ImageUploadService $imageUploadService) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        // 078 — jatah percobaan login per akun. Dua hitungan: (1) username + IP, 5 per menit; (2) username saja, 30 per menit dari SEMUA IP,
        // supaya tebakan yang disebar lintas alamat tetap dibatasi — sengaja lebih longgar dari (1) agar penyerang tidak bisa mengunci
        // owner dari luar. Kunci memakai username yang DIKIRIM, ada atau tidak, jadi akun yang tidak ada tidak bisa dibedakan dari
        // yang ada (tanpa enumerasi). Lalu lintas lain dari IP yang sama (mis. polling tablet) tidak ikut terhitung.
        // `hit` DULU lalu bandingkan (increment atomik di cache): urutan "cek lalu hit" membiarkan permintaan paralel lolos melewati batas
        // sebelum kegagalan pertama tercatat. Login yang berhasil menghapus hitungan username+IP.
        $username = mb_strtolower($credentials['username']);
        $key = 'login|'.$username.'|'.$request->ip();
        $userKey = 'login-user|'.$username;
        $byAddress = RateLimiter::hit($key, self::LOGIN_DECAY_SECONDS);
        $byAccount = RateLimiter::hit($userKey, self::LOGIN_DECAY_SECONDS);
        if ($byAddress > self::LOGIN_MAX_ATTEMPTS || $byAccount > self::LOGIN_MAX_ATTEMPTS_PER_ACCOUNT) {
            $wait = max(
                $byAddress > self::LOGIN_MAX_ATTEMPTS ? RateLimiter::availableIn($key) : 0,
                $byAccount > self::LOGIN_MAX_ATTEMPTS_PER_ACCOUNT ? RateLimiter::availableIn($userKey) : 0,
            );
            throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => max(1, $wait)]);
        }

        $user = User::where('username', $credentials['username'])->first();

        // Pesan error disamakan untuk "user tidak ada" dan "password salah"
        // agar tidak membocorkan username mana yang valid (OWASP —
        // Improper Error Handling / user enumeration).
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => __('auth.invalid_credentials'),
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => __('auth.inactive_account'),
            ], 401);
        }

        RateLimiter::clear($key);

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken('pos-session')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }

    /**
     * Self-service — lihat komentar di UpdateLanguageRequest. Tidak ada
     * gerbang Policy di sini karena preferensi bahasa bukan hak akses
     * menu; setiap akun boleh mengubah bahasanya sendiri.
     */
    public function updateLanguage(UpdateLanguageRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['language' => $request->validated('language')]);

        return response()->json($this->userPayload($user));
    }

    /**
     * 005-ux-enhancements-dashboard (US3) — swa-layanan ganti password.
     * Sengaja TIDAK memakai UserPolicy (sama seperti updateLanguage()):
     * mengganti password akun sendiri bukan hak akses berbasis menu, jadi
     * kasir/inventory yang tidak punya canAccessMenu('users') tetap harus
     * bisa. Verifikasi current_password dilakukan di sini (bukan di
     * FormRequest) karena butuh $request->user(). Token sesi yang sedang
     * dipakai TIDAK dicabut — beda dari reset password oleh admin
     * (UserController), yang boleh membuat sesi lama basi.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->validated('current_password'), $user->password)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'current_password' => [__('auth.current_password_incorrect')],
            ]);
        }

        $user->update(['password' => $request->validated('password')]);

        return response()->json(['message' => __('auth.password_updated')]);
    }

    /**
     * 005-ux-enhancements-dashboard (US3) — swa-layanan ganti foto profil.
     * Sengaja route/endpoint TERPISAH dari UserController::uploadPhoto(),
     * yang digerbangi UserPolicy::update() (canAccessMenu('users')) — rute
     * itu untuk admin/owner mengubah foto akun LAIN. Endpoint ini hanya
     * pernah menyentuh $request->user() sendiri (tidak menerima {user}
     * dari klien sama sekali), jadi aman dipakai kasir/inventory yang
     * gagal UserPolicy::update() untuk mengubah foto MEREKA SENDIRI.
     * Lihat research.md R6.
     */
    public function updatePhoto(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => [
                'required',
                'file',
                Rule::file()->max(ImageUploadService::MAX_KILOBYTES)->rules(['mimes:jpeg,png']),
            ],
        ]);

        $user = $request->user();
        $oldPath = $user->photo_path;

        $user->photo_path = $this->imageUploadService->store($validated['image'], 'users');
        $user->save();

        $this->imageUploadService->delete($oldPath);

        return response()->json($this->userPayload($user->fresh('role')));
    }

    public function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            // 'role' sekarang nama Role (string) yang bisa diedit
            // pemilik toko — dipakai HANYA untuk tampilan (mis. label
            // di footer AppSidebar.vue), bukan lagi untuk keputusan
            // otorisasi apa pun di frontend (lihat menu_keys di bawah).
            'role' => $user->role?->name,
            // T009 — permukaan resolusi menu_keys sekali per sesi frontend
            // (bukan per-menu, lihat plan.md Performance Goals).
            'menu_keys' => $user->role?->menu_keys ?? [],
            // 043 — izin tingkat-aksi yang BERLAKU (bawaan menurut nama peran bila belum diatur); UI hanya menyembunyikan,
            // server tetap penegak (User::hasPermission).
            'permissions' => $user->role ? array_values(array_filter(\App\Support\PermissionCatalog::keys(), fn ($k) => $user->role->allows($k))) : [],
            'is_active' => $user->is_active,
            'language' => $user->language,
            'photo_url' => $this->imageUploadService->url($user->photo_path),
        ];
    }
}
