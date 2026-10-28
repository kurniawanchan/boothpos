<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterTerminalRequest;
use App\Models\Device;
use App\Models\Outlet;
use App\Services\DeviceService;
use App\Support\StoreBranding;
use Illuminate\Http\JsonResponse;

/**
 * 073 — penyiapan tablet tanpa login owner/admin: pilih outlet, pilih/daftarkan terminal. Rute TANPA token.
 *
 * Pengecualian Konstitusi IV (plan.md → Complexity Tracking): sebelum masuk belum ada pengguna yang bisa menurunkan
 * outlet, jadi outlet datang dari PATH dan hanya diperiksa aktif. Hanya nama (id + nama) yang keluar dari sini —
 * tidak ada metadata perangkat, pengguna, atau data usaha. Aturan nama tetap di DeviceService (satu jalur tulis).
 */
class PosSetupController extends Controller
{
    public function __construct(private DeviceService $devices) {}

    public function outlets(): JsonResponse
    {
        return response()->json(['data' => Outlet::query()->where('is_active', true)->orderBy('name')->get()
            ->map(fn (Outlet $o) => ['id' => $o->id, 'name' => $o->name])->values()]);
    }

    /**
     * 077 — nama toko, logo, dan mode data untuk layar PIN/penyiapan tablet (sebelum ada token). Pengecualian Konstitusi IV
     * (plan.md): hanya tiga nilai yang sudah terlihat semua peran lewat `GET /settings/features`; tidak ada data usaha lain.
     * Mode ikut dikirim karena outlet dan terminal berbeda per mode — tablet harus tahu mode mana yang sedang berlaku.
     */
    public function branding(): JsonResponse
    {
        return response()->json(StoreBranding::forTablet());
    }

    /**
     * 079 — sama dengan [branding], tetapi `store_name` = nama outlet pilihan tablet (outlet dari path, divalidasi aktif; 404
     * bila tidak ada di mode data yang berlaku). Tetap hanya tiga nilai.
     */
    public function outletBranding(int $outlet): JsonResponse
    {
        return response()->json(StoreBranding::forTablet($this->activeOutlet($outlet)));
    }

    public function terminals(int $outlet): JsonResponse
    {
        $outlet = $this->activeOutlet($outlet);

        return response()->json(['data' => Device::query()->where('outlet_id', $outlet->id)->whereNull('revoked_at')->orderBy('name')->get()
            ->map(fn (Device $d) => ['id' => $d->id, 'name' => $d->name])->values()]);
    }

    public function registerTerminal(RegisterTerminalRequest $request, int $outlet): JsonResponse
    {
        $result = $this->devices->findOrRegister($this->activeOutlet($outlet), $request->validated()['name']);

        return response()->json([
            'id' => $result['device']->id, 'name' => $result['device']->name, 'created' => $result['created'],
        ], $result['created'] ? 201 : 200);
    }

    /** Outlet aktif pada mode data berlaku (Outlet::query() memakai DataModeScope); selain itu 404. */
    private function activeOutlet(int $id): Outlet
    {
        return Outlet::query()->where('is_active', true)->findOrFail($id);
    }
}
