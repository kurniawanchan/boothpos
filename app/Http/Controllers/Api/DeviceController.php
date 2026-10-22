<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeviceHeartbeatRequest;
use App\Http\Requests\PairDeviceRequest;
use App\Http\Requests\RenameDeviceRequest;
use App\Models\Device;
use App\Services\DeviceService;
use App\Support\ServerAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 048 — pendaftaran terminal POS. Semua rute: owner/admin.
 *
 * Pembatasan outlet: `Device` memakai BelongsToOutlet → OutletScope, jadi daftar dan route-model binding (`destroy`)
 * hanya melihat outlet yang diizinkan permintaan (penugasan pengguna ∩ X-Outlet-Id). Perangkat outlet lain → 404.
 * Dibuktikan oleh DevicePairingTest (…_scoped_to_the_selected_outlet, …_outlet_assigned_admin_…).
 */
class DeviceController extends Controller
{
    public function __construct(private DeviceService $devices) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isOwnerOrAdmin(), 403, __('devices.forbidden'));

        return response()->json(['data' => Device::query()->orderBy('name')->get()->map(fn (Device $d) => $this->present($d))->values()]);
    }

    public function pair(PairDeviceRequest $request): JsonResponse
    {
        return response()->json($this->present($this->devices->pair($request->validated(), $request->user())), 201);
    }

    /** 064 — ganti nama terminal (owner/admin; perangkat outlet lain → 404 lewat route-model binding ber-scope). */
    public function rename(RenameDeviceRequest $request, Device $device): JsonResponse
    {
        return response()->json($this->present($this->devices->rename($device, $request->validated()['name'], $request->user())));
    }

    /**
     * 048 / T064 — heartbeat terminal (semua pengguna terautentikasi; sesi kasir yang sedang login juga boleh).
     * Perangkat ditentukan dari X-Device-Id: harus terminal aktif milik outlet yang terlihat pengguna
     * (OutletScope pada Device). Tanpa header, atau perangkat tak dikenal/dicabut/outlet lain → 422 `device_id`.
     */
    public function heartbeat(DeviceHeartbeatRequest $request, DeviceService $devices): JsonResponse
    {
        return response()->json($devices->heartbeat($this->activeDevice($request), $request->validated()));
    }

    /**
     * 054 / US3 — alamat server yang dipilih admin untuk terminal ini. Tablet memanggilnya setelah roster berhasil
     * (research.md R3). Hanya `selected` dan `url` yang dikirim: alamat satunya tidak pernah sampai ke tablet (FR-011).
     * Memakai pengecekan perangkat yang sama dengan heartbeat (activeDevice), bukan salinannya.
     */
    public function serverAddress(Request $request): JsonResponse
    {
        $this->activeDevice($request);

        return response()->json([
            'selected' => ServerAddress::current()['selected'],
            'url' => ServerAddress::selectedUrl(),
        ]);
    }

    /** Perangkat dari X-Device-Id harus aktif (tidak dicabut) dan terlihat untuk outlet pengguna; selain itu 422. */
    private function activeDevice(Request $request): Device
    {
        $header = $request->header('X-Device-Id');
        $device = empty($header) ? null : Device::query()->whereNull('revoked_at')->find((int) $header);
        if ($device === null) {
            throw ValidationException::withMessages(['device_id' => __('devices.invalid_device')]);
        }

        return $device;
    }

    public function destroy(Request $request, Device $device): JsonResponse
    {
        abort_unless($request->user()->isOwnerOrAdmin(), 403, __('devices.forbidden'));
        $this->devices->revoke($device, $request->user());

        return response()->json(null, 204);
    }

    private function present(Device $d): array
    {
        return [
            'id' => $d->id, 'name' => $d->name, 'outlet_id' => $d->outlet_id,
            'app_version' => $d->app_version, 'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            'revoked' => $d->revoked_at !== null,
        ];
    }
}
