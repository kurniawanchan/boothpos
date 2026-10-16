<?php

namespace App\Services;

use App\Models\Device;
use App\Models\User;
use App\Support\OutletContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 048 / T007 (api-gap B14) — daftar terminal POS per outlet. Satu-satunya jalur membuat/mencabut perangkat.
 *
 * Outlet DITURUNKAN server dari konteks (OutletContext::singleId: satu-satunya outlet yang berlaku, atau yang dipilih
 * lewat X-Outlet-Id dan memang dimiliki pengguna) — tidak pernah dibaca dari body (Konstitusi IV). Pemilik/admin yang
 * melihat banyak outlet WAJIB memilih satu. Kode `T01`, `T02`… dibuat otomatis bila kosong dan unik per outlet.
 */
class DeviceService
{
    public function __construct(private OutletContext $outlets, private ActivityLogger $log) {}

    /** @param array{name: string, code?: ?string} $data */
    public function pair(array $data, User $by): Device
    {
        $outletId = $this->outlets->singleId();
        if ($outletId === null) {
            throw ValidationException::withMessages(['outlet' => [__('devices.pick_outlet')]]);
        }

        return DB::transaction(function () use ($data, $by, $outletId) {
            $code = isset($data['code']) && $data['code'] !== '' ? strtoupper($data['code']) : $this->nextCode($outletId);

            try {
                $device = new Device(['code' => $code, 'name' => $data['name'], 'paired_at' => now()]);
                $device->setAttribute('outlet_id', $outletId);
                $device->setAttribute('paired_by', $by->id);
                $device->save();
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'uniq_devices_outlet_mode_code')) {
                    throw ValidationException::withMessages(['code' => [__('devices.code_taken', ['code' => $code])]]);
                }
                throw $e;
            }

            DB::table('terminal_counters')->insert(['device_id' => $device->id, 'receipt_seq' => 0]);
            $this->log->log($by->id, 'device_paired', 'Device', $device->id, "Terminal {$device->code} didaftarkan");

            return $device;
        });
    }

    public function revoke(Device $device, User $by): Device
    {
        return DB::transaction(function () use ($device, $by) {
            if ($device->revoked_at === null) {
                $device->forceFill(['revoked_at' => now()])->save();
                $this->log->log($by->id, 'device_revoked', 'Device', $device->id, "Terminal {$device->code} dicabut");
            }

            return $device;
        });
    }

    /** T01, T02, … — mengisi celah terkecil agar kode perangkat yang dicabut tidak dipakai ulang diam-diam. */
    private function nextCode(int $outletId): string
    {
        $max = Device::query()->withoutGlobalScopes()->where('outlet_id', $outletId)->pluck('code')
            ->map(fn ($c) => preg_match('/^T(\d+)$/', $c, $m) ? (int) $m[1] : 0)->max() ?? 0;

        return 'T'.str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT);
    }

    /**
     * 048 / T064 — heartbeat terminal. Jam perangkat dibandingkan dengan jam server:
     * `skew_seconds` = (clock_ms − server_time_ms) / 1000 dibulatkan; POSITIF berarti jam perangkat MAJU
     * dari server. Disimpan di `clock_skew_seconds` dan dikembalikan apa adanya. Hanya baris perangkat
     * yang berubah: tanpa catatan aktivitas per heartbeat (terlalu sering, tiap 60 dtk).
     *
     * @param  array{app_version: string, clock_ms: int, queue_depth: int}  $data  queue_depth divalidasi saja
     * @return array{server_time_ms: int, skew_seconds: int, menu_release: ?int}
     */
    public function heartbeat(Device $device, array $data): array
    {
        $serverMs = now()->getTimestampMs();
        $skew = (int) round(((int) $data['clock_ms'] - $serverMs) / 1000);

        $device->forceFill([
            'app_version' => $data['app_version'],
            'last_seen_at' => now(),
            'clock_skew_seconds' => $skew,
        ])->save();

        return [
            'server_time_ms' => $serverMs,
            'skew_seconds' => $skew,
            'menu_release' => $this->appliedMenuRelease(),
        ];
    }

    /**
     * Versi rilis menu yang sedang berlaku: rilis TERBIT tertinggi. Null bila mesin rilis mati
     * (`menu_release_enabled` = false, bawaan) atau belum ada rilis yang terbit.
     */
    private function appliedMenuRelease(): ?int
    {
        if (! \App\Support\MenuSettings::releaseEnabled()) {
            return null;
        }

        $version = \App\Models\MenuRelease::query()->where('status', \App\Models\MenuRelease::PUBLISHED)->max('version');

        return $version === null ? null : (int) $version;
    }
}

