<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PinRejectedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SetUserPinRequest;
use App\Http\Resources\UserResource;
use App\Models\Outlet;
use App\Models\User;
use App\Services\DeviceService;
use App\Services\PinAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 048 / api-gap B1 — daftar staf terminal (073: roster dan login PIN tanpa token), login PIN, ganti PIN, perpanjang token, reset PIN oleh admin. */
class PinAuthController extends Controller
{
    public function __construct(private PinAuthService $pins, private AuthController $auth) {}

    /** Hanya data yang dibutuhkan layar pilih-staf: tanpa username, tanpa hash. */
    public function roster(Request $request): JsonResponse
    {
        $data = $request->validate(['outlet_id' => ['required', 'integer']]);
        $outlet = Outlet::where('is_active', true)->findOrFail($data['outlet_id']);

        return response()->json(['data' => $this->pins->roster($outlet)->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'role' => $u->role?->name,
            'has_pin' => $u->pin_hash !== null,
            'locked' => $u->pin_locked_until?->isFuture() ?? false,
        ])->values()]);
    }

    /**
     * 073 — tanpa token (tablet belum login). `device_id` wajib: terminal yang dipilih di layar penyiapan, diperiksa aktif dan
     * milik outlet ini sebelum PIN disentuh.
     */
    public function login(Request $request, DeviceService $devices): JsonResponse
    {
        $data = $request->validate([
            'outlet_id' => ['required', 'integer'],
            'user_id' => ['required', 'integer'],
            'pin' => ['required', 'digits:6'],
            'device_id' => ['required', 'integer'],
        ], ['device_id.required' => __('devices.device_required')]);

        return $this->guarded(function () use ($data, $devices) {
            $outlet = Outlet::where('is_active', true)->find($data['outlet_id']);
            if ($outlet === null) {
                throw new PinRejectedException(__('auth.invalid_pin'), 'invalid_pin', 401);
            }
            $device = $devices->activeTerminalOf($outlet, (int) $data['device_id']);
            $issued = $this->pins->login($outlet, (int) $data['user_id'], $data['pin'], $device);

            return response()->json([
                'token' => $issued['token'],
                'expires_at' => $issued['expires_at']->toIso8601String(),
                'user' => $this->auth->userPayload($issued['user']->load('role')),
            ]);
        });
    }

    public function change(Request $request): JsonResponse
    {
        $data = $request->validate([
            'new_pin' => ['required', 'digits:6'],
            'current_pin' => ['nullable', 'digits:6'],
            'current_password' => ['nullable', 'string'],
        ]);

        return $this->guarded(function () use ($request, $data) {
            $this->pins->change($request->user(), $data['new_pin'], $data['current_pin'] ?? null, $data['current_password'] ?? null);

            return response()->json(['message' => __('auth.pin_changed')]);
        });
    }

    public function refresh(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request) {
            $issued = $this->pins->refresh($request->user());

            return response()->json(['token' => $issued['token'], 'expires_at' => $issued['expires_at']->toIso8601String()]);
        });
    }

    public function reset(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->isOwnerOrAdmin(), 403, __('restaurant.forbidden'));
        $this->pins->reset($request->user(), $user);

        return response()->json(null, 204);
    }

    /**
     * 074 — owner/admin mengatur PIN pengguna dari layar Pengguna. PIN TIDAK ada di respons: yang kembali hanya pengguna
     * (dengan `has_pin: true`). Otorisasi di SetUserPinRequest.
     */
    public function setUserPin(SetUserPinRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(function () use ($request, $user, $data) {
            $this->pins->adminSet($request->user(), $user, $data['pin'], $data['source'] ?? 'typed');

            return response()->json(new UserResource($user->fresh(['role', 'outlets:id'])));
        });
    }

    /** 074 — saran PIN acak untuk tombol "Generate" (POST agar tidak tersimpan di cache). Hanya owner/admin; tidak disimpan. */
    public function suggest(Request $request): JsonResponse
    {
        abort_unless($request->user()->isOwnerOrAdmin(), 403, __('restaurant.forbidden'));

        return response()->json(['pin' => $this->pins->suggest()]);
    }

    private function guarded(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (PinRejectedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode] + $e->extra, $e->status);
        }
    }
}
