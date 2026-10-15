<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PairDeviceRequest;
use App\Models\Device;
use App\Services\DeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 048 — pendaftaran terminal POS. Semua rute: owner/admin. */
class DeviceController extends Controller
{
    public function __construct(private DeviceService $devices) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isOwnerOrAdmin(), 403, __('devices.forbidden'));

        return response()->json(['data' => Device::query()->orderBy('code')->get()->map(fn (Device $d) => $this->present($d))->values()]);
    }

    public function pair(PairDeviceRequest $request): JsonResponse
    {
        return response()->json($this->present($this->devices->pair($request->validated(), $request->user())), 201);
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
            'id' => $d->id, 'code' => $d->code, 'name' => $d->name, 'outlet_id' => $d->outlet_id,
            'app_version' => $d->app_version, 'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            'revoked' => $d->revoked_at !== null,
        ];
    }
}
