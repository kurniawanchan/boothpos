<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::where('username', $credentials['username'])->first();

        // Pesan error disamakan untuk "user tidak ada" dan "password salah"
        // agar tidak membocorkan username mana yang valid (OWASP —
        // Improper Error Handling / user enumeration).
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Username atau password salah.',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Akun tidak aktif. Hubungi admin.',
            ], 401);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken('pos-session')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                // 'role' sekarang nama Role (string) yang bisa diedit
                // pemilik toko — dipakai HANYA untuk tampilan (mis. label
                // di footer AppSidebar.vue), bukan lagi untuk keputusan
                // otorisasi apa pun di frontend (lihat menu_keys di bawah).
                'role' => $user->role?->name,
                'menu_keys' => $user->role?->menu_keys ?? [],
                'is_active' => $user->is_active,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'role' => $user->role?->name,
            // T009 — permukaan resolusi menu_keys sekali per sesi frontend
            // (bukan per-menu, lihat plan.md Performance Goals).
            'menu_keys' => $user->role?->menu_keys ?? [],
            'is_active' => $user->is_active,
        ]);
    }
}
