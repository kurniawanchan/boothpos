<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PinRejectedException;
use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\User;
use App\Services\PinAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 048 / api-gap B1 — daftar staf terminal, login PIN, ganti PIN, perpanjang token, reset PIN oleh admin. */
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

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'outlet_id' => ['required', 'integer'],
            'user_id' => ['required', 'integer'],
            'pin' => ['required', 'digits:6'],
        ]);

        return $this->guarded(function () use ($data) {
            $outlet = Outlet::where('is_active', true)->find($data['outlet_id']);
            if ($outlet === null) {
                throw new PinRejectedException(__('auth.invalid_pin'), 'invalid_pin', 401);
            }
            $issued = $this->pins->login($outlet, (int) $data['user_id'], $data['pin']);

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

    private function guarded(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (PinRejectedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode] + $e->extra, $e->status);
        }
    }
}
