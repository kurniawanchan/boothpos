<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkDeviceRequest;
use App\Http\Requests\DeviceHeartbeatRequest;
use App\Http\Requests\DeviceIndexRequest;
use App\Http\Requests\PairDeviceRequest;
use App\Http\Requests\UpdateDeviceRequest;
use App\Models\Device;
use App\Services\DeviceService;
use App\Support\OutletContext;
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

    /**
     * 079 — daftar berfilter untuk halaman Terminal: `outlet_id[]` (hanya menyempitkan dalam outlet yang boleh dilihat),
     * `status` (active|revoked|all, bawaan all), `q` (nama mengandung). Berhalaman bila `page`/`per_page` dikirim; tanpa
     * keduanya bentuk lama `{data}` tetap (pemanggil dan tes lama tidak berubah).
     */
    public function index(DeviceIndexRequest $request, OutletContext $outlets): JsonResponse
    {
        $v = $request->validated();
        $query = Device::query()->with('outlet:id,code,name')->orderBy('name')->orderBy('id');

        // [] (tak satu pun outlet yang diminta boleh dilihat) = hasil kosong, bukan "semua".
        $narrowed = $outlets->narrowedOrNull($v['outlet_id'] ?? []);
        if ($narrowed !== null) {
            $query->whereIn('outlet_id', $narrowed);
        }
        match ($v['status'] ?? 'all') {
            'active' => $query->whereNull('revoked_at'),
            'revoked' => $query->whereNotNull('revoked_at'),
            default => null,
        };
        if (filled($v['q'] ?? null)) {
            $query->where('name', 'like', '%'.addcslashes(trim($v['q']), '\\%_').'%');
        }

        if (! isset($v['page']) && ! isset($v['per_page'])) {
            return response()->json(['data' => $query->get()->map(fn (Device $d) => $this->present($d))->values()]);
        }

        $page = $query->paginate((int) ($v['per_page'] ?? 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (Device $d) => $this->present($d))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function pair(PairDeviceRequest $request): JsonResponse
    {
        return response()->json($this->present($this->devices->pair($request->validated(), $request->user())), 201);
    }

    /** 064 — ganti nama; 079 — dan/atau pindah outlet (owner/admin; perangkat outlet lain → 404 lewat route-model binding ber-scope). */
    public function update(UpdateDeviceRequest $request, Device $device, OutletContext $outlets): JsonResponse
    {
        $toOutletId = $request->filled('outlet_id') ? $request->integer('outlet_id') : null;
        if ($toOutletId !== null && ! $outlets->isPermitted($toOutletId)) {
            return $this->outletForbidden();
        }

        return response()->json($this->present($this->devices->update($device, $request->safe()->only('name'), $request->user(), $toOutletId)));
    }

    /**
     * 079 — aksi massal: `{action: activate|deactivate|delete, ids: []}`. Selalu 200 dengan hasil per terminal
     * (`changed|skipped|refused|not_found`); satu terminal yang ditolak tidak menggagalkan yang lain.
     */
    public function bulk(BulkDeviceRequest $request): JsonResponse
    {
        $results = $this->devices->bulk(array_map('intval', $request->validated('ids')), $request->validated('action'), $request->user());

        return response()->json([
            'data' => $results,
            'summary' => collect(['changed', 'skipped', 'refused', 'not_found'])
                ->mapWithKeys(fn (string $status) => [$status => collect($results)->where('status', $status)->count()])
                ->all(),
        ]);
    }

    /** 079 — aktifkan lagi terminal yang dinonaktifkan. */
    public function reactivate(Request $request, Device $device): JsonResponse
    {
        abort_unless($request->user()->isOwnerOrAdmin(), 403, __('devices.forbidden'));

        return response()->json($this->present($this->devices->reactivate($device, $request->user())));
    }

    /** 079 — hapus permanen; hanya terminal yang belum pernah dipakai (409 bila punya order/shift). */
    public function destroyPermanent(Request $request, Device $device): JsonResponse
    {
        abort_unless($request->user()->isOwnerOrAdmin(), 403, __('devices.forbidden'));
        $this->devices->deleteIfUnused($device, $request->user());

        return response()->json(null, 204);
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

    private function outletForbidden(): JsonResponse
    {
        return response()->json(['message' => __('outlets.forbidden'), 'code' => 'outlet_forbidden'], 403);
    }

    private function present(Device $d): array
    {
        $d->loadMissing('outlet:id,code,name');

        return [
            'id' => $d->id, 'name' => $d->name, 'outlet_id' => $d->outlet_id,
            // 079 — outlet untuk halaman Terminal (kode + nama) dan waktu daftar/cabut.
            'outlet' => $d->outlet ? ['id' => $d->outlet->id, 'code' => $d->outlet->code, 'name' => $d->outlet->name] : null,
            'app_version' => $d->app_version, 'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            'paired_at' => $d->paired_at?->toIso8601String(),
            'revoked_at' => $d->revoked_at?->toIso8601String(),
            'revoked' => $d->revoked_at !== null,
        ];
    }
}
