<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ManagerSessionRequest;
use App\Services\CredentialLogin;
use App\Support\OutletContext;
use Illuminate\Http\JsonResponse;

/**
 * 094 / FR-004–FR-010 — login aplikasi Android manajemen. Kredensial dan jatah percobaan memakai CredentialLogin yang SAMA
 * dengan login web. Setelah kredensial benar: peran wajib memegang `manager_app_enabled`, dan outlet yang dipilih sebelum
 * login harus termasuk outlet yang boleh dilihat pengguna. `outlet_id` di body hanya untuk MEMERIKSA (Konstitusi IV:
 * permintaan hanya menyempitkan); setelah login aplikasi mengirim outlet lewat header X-Outlet-Id seperti klien lain.
 */
class ManagerSessionController extends Controller
{
    /** Nama token Sanctum yang dikenali EnsureManagerAppAccess. */
    public const TOKEN_NAME = 'manager-app';

    public function __construct(private CredentialLogin $credentialLogin, private AuthController $auth) {}

    public function store(ManagerSessionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $this->credentialLogin->attempt($data['username'], $data['password'], $request->ip());

        if (! $user->role?->manager_app_enabled) {
            return response()->json([
                'message' => 'Akun ini tidak punya akses ke aplikasi manajemen.',
                'code' => 'manager_app_denied',
            ], 403);
        }

        // Konteks lokal, bukan singleton permintaan: rute ini tanpa auth, jadi singleton belum terikat ke pengguna mana pun.
        $outlets = new OutletContext;
        $outlets->bind($user, null);
        if (! $outlets->isPermitted((int) $data['outlet_id'])) {
            return response()->json([
                'message' => 'Akun ini tidak terdaftar di outlet yang dipilih.',
                'code' => 'outlet_not_permitted',
            ], 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'token' => $user->createToken(self::TOKEN_NAME)->plainTextToken,
            'user' => $this->auth->userPayload($user),
        ]);
    }
}
