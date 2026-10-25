<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Outlet;
use App\Models\User;
use App\Support\OutletContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 048 / T007 (api-gap B14) — daftar terminal POS per outlet. Satu-satunya jalur membuat/mencabut perangkat.
 *
 * Outlet DITURUNKAN server dari konteks (OutletContext::singleId: satu-satunya outlet yang berlaku, atau yang dipilih
 * lewat X-Outlet-Id dan memang dimiliki pengguna) — tidak pernah dibaca dari body (Konstitusi IV). Pemilik/admin yang
 * melihat banyak outlet WAJIB memilih satu.
 *
 * 064 — terminal dikenali HANYA lewat NAMA. Tidak ada lagi kode otomatis `T01`/`T02` (kolom `code` hanya riwayat).
 * Nama unik di antara terminal AKTIF satu outlet + mode data, tanpa membedakan huruf besar/kecil. Aturannya dijaga di
 * sini (di bawah kunci baris outlet), bukan indeks unik: terminal yang dicabut harus melepas namanya, dan kolom
 * generated untuk itu merusak restore backup (lihat catatan 045 `orders.open_table_key`).
 */
class DeviceService
{
    public function __construct(private OutletContext $outlets, private ActivityLogger $log) {}

    /** @param array{name: string} $data */
    public function pair(array $data, User $by): Device
    {
        $outletId = $this->outlets->singleId();
        if ($outletId === null) {
            throw ValidationException::withMessages(['outlet' => [__('devices.pick_outlet')]]);
        }

        return DB::transaction(function () use ($data, $by, $outletId) {
            $name = $this->assertNameFree($outletId, $data['name'] ?? '');

            $device = new Device(['name' => $name, 'paired_at' => now()]);
            $device->setAttribute('outlet_id', $outletId);
            $device->setAttribute('paired_by', $by->id);
            $device->save();

            $this->log->log($by->id, 'device_paired', 'Device', $device->id, "Terminal {$device->name} didaftarkan");

            return $device;
        });
    }

    /**
     * 073 — terminal AKTIF milik outlet ini, atau 422 `device_id` dengan pesan yang sama seperti heartbeat. Dipakai login
     * PIN supaya "masuk di terminal X" bukan klaim klien semata (FR-009); terminal outlet lain, dicabut, atau tak dikenal
     * ditolak SEBELUM PIN diperiksa, jadi tidak menaikkan hitungan salah-PIN siapa pun.
     */
    public function activeTerminalOf(Outlet $outlet, int $deviceId): Device
    {
        $device = Device::query()->where('outlet_id', $outlet->id)->whereNull('revoked_at')->find($deviceId);
        if ($device === null) {
            throw ValidationException::withMessages(['device_id' => __('devices.invalid_device')]);
        }

        return $device;
    }

    /** 064 — ganti nama terminal. Terminal yang sudah dicabut tidak bisa diganti (409). */
    public function rename(Device $device, string $name, User $by): Device
    {
        return DB::transaction(function () use ($device, $name, $by) {
            if ($device->revoked_at !== null) {
                throw ValidationException::withMessages(['device' => [__('devices.revoked_no_rename')]])->status(409);
            }

            $new = $this->assertNameFree((int) $device->outlet_id, $name, $device->id);
            $old = $device->name;
            if ($new !== $old) {
                $device->forceFill(['name' => $new])->save();
                $this->log->log($by->id, 'device_renamed', 'Device', $device->id, "Terminal {$old} diganti namanya menjadi {$new}", ['name' => $old], ['name' => $new]);
            }

            return $device;
        });
    }

    /**
     * 073 — pilih-atau-daftarkan terminal dari layar penyiapan tablet (tanpa pengguna login). Nama yang sudah dipakai
     * terminal AKTIF outlet ini (tanpa beda huruf/spasi) mengembalikan terminal itu, BUKAN galat: tablet kedua yang
     * mengetik nama yang sama berarti "terminal ini" (FR-006/FR-017). Nama kosong tetap 422.
     *
     * Outlet datang dari path rute dan sudah divalidasi aktif oleh pemanggil — pengecualian Konstitusi IV yang dicatat
     * di plan.md (belum ada pengguna untuk menurunkannya). Aturan nama sama persis dengan `pair()` (cleanName +
     * activeByName di bawah kunci baris outlet), jadi dua tablet yang mengetik nama baru yang sama serentak
     * menghasilkan satu baris.
     *
     * @return array{device: Device, created: bool}
     */
    public function findOrRegister(Outlet $outlet, string $name): array
    {
        return DB::transaction(function () use ($outlet, $name) {
            $name = $this->cleanName($name);
            $this->lockOutlet((int) $outlet->id);

            $existing = $this->activeByName((int) $outlet->id, $name);
            if ($existing !== null) {
                return ['device' => $existing, 'created' => false];
            }

            $device = new Device(['name' => $name, 'paired_at' => now()]);
            $device->setAttribute('outlet_id', $outlet->id);
            $device->save();

            // Tanpa pengguna: pelaku null (activity_logs.user_id nullable). Satu transaksi dengan penyimpanannya.
            $this->log->log(null, 'device_registered', 'Device', $device->id, "Terminal {$device->name} didaftarkan dari tablet (outlet {$outlet->name})");

            return ['device' => $device, 'created' => true];
        });
    }

    /**
     * Nama (dipangkas) wajib diisi dan belum dipakai terminal AKTIF lain di outlet + mode ini. Baris outlet dikunci
     * dulu supaya dua pendaftaran serentak dengan nama sama tidak lolos bersamaan.
     */
    private function assertNameFree(int $outletId, string $name, ?int $exceptId = null): string
    {
        $name = $this->cleanName($name);
        $this->lockOutlet($outletId);

        if ($this->activeByName($outletId, $name, $exceptId) !== null) {
            throw ValidationException::withMessages(['name' => [__('devices.name_taken', ['name' => $name])]]);
        }

        return $name;
    }

    private function cleanName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => [__('devices.name_required')]]);
        }

        return $name;
    }

    private function lockOutlet(int $outletId): void
    {
        DB::table('outlets')->where('id', $outletId)->lockForUpdate()->first();
    }

    private function activeByName(int $outletId, string $name, ?int $exceptId = null): ?Device
    {
        return Device::query()->where('outlet_id', $outletId)->whereNull('revoked_at')
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
    }

    public function revoke(Device $device, User $by): Device
    {
        return DB::transaction(function () use ($device, $by) {
            if ($device->revoked_at === null) {
                $device->forceFill(['revoked_at' => now()])->save();
                $this->log->log($by->id, 'device_revoked', 'Device', $device->id, "Terminal {$device->name} dicabut");
            }

            return $device;
        });
    }

    /**
     * 048 / T064 — heartbeat terminal. Jam perangkat dibandingkan dengan jam server:
     * `skew_seconds` = (clock_ms − server_time_ms) / 1000 dibulatkan; POSITIF berarti jam perangkat MAJU
     * dari server. Disimpan di `clock_skew_seconds` dan dikembalikan apa adanya. Hanya baris perangkat
     * yang berubah: tanpa catatan aktivitas per heartbeat (terlalu sering, tiap 60 dtk).
     *
     * @param  array{app_version: string, clock_ms: int, queue_depth: int}  $data  queue_depth divalidasi saja
     * @return array{server_time_ms: int, skew_seconds: int, menu_release: ?int, name: string}
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
            // 064 — tablet yang dipasang sebelum fitur ini belum menyimpan nama terminalnya; heartbeat mengisinya.
            'name' => $device->name,
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

