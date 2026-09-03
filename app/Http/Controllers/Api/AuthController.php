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
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function __construct(private ImageUploadService $imageUploadService) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

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

    private function userPayload(User $user): array
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
            'is_active' => $user->is_active,
            'language' => $user->language,
            'photo_url' => $this->imageUploadService->url($user->photo_path),
        ];
    }
}
