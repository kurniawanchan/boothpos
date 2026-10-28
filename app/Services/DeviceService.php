<?php

namespace App\Services;

use App\Models\CashierSession;
use App\Models\Device;
use App\Models\Order;
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
 * melihat banyak outlet WAJIB memilih satu; halaman Terminal (079) memilihnya lewat header itu juga. Satu-satunya
 * pengecualian adalah PINDAH outlet ([update]): tujuan pindah tidak bisa berupa header, jadi `outlet_id` di body
 * diperiksa pemanggil terhadap OutletContext::permittedIds() (outlet aktif yang boleh dilihat), pola yang sama dengan
 * pindah meja/stasiun (071).
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

    /**
     * 064 — ganti nama; 079 — dan/atau pindah ke outlet lain ([toOutletId] sudah diperiksa pemanggil: aktif dan boleh dilihat
     * pengguna). Terminal yang sudah dinonaktifkan tidak bisa diubah (409): aktifkan dulu. Pindah ditolak (409) bila ada shift
     * terbuka di terminal ini ([OutletMoveGuard]); nama dicek ulang di outlet TUJUAN. Riwayat (order, shift, struk) tetap pada
     * outlet transaksinya — hanya `devices.outlet_id` yang berubah. Satu transaksi dengan log-nya.
     *
     * @param  array{name?: string}  $data
     */
    public function update(Device $device, array $data, User $by, ?int $toOutletId = null): Device
    {
        return DB::transaction(function () use ($device, $data, $by, $toOutletId) {
            if ($device->revoked_at !== null) {
                throw ValidationException::withMessages(['device' => [__('devices.revoked_no_edit')]])->status(409);
            }

            $fromOutletId = (int) $device->outlet_id;
            $moving = $toOutletId !== null && $toOutletId !== $fromOutletId;
            if ($moving && ($blocker = app(OutletMoveGuard::class)->deviceBlocker($device)) !== null) {
                throw ValidationException::withMessages(['device' => [app(OutletMoveGuard::class)->message($blocker)]])->status(409);
            }

            $targetOutletId = $moving ? $toOutletId : $fromOutletId;
            $old = $device->name;
            $new = array_key_exists('name', $data) ? $this->assertNameFree($targetOutletId, $data['name'] ?? '', $device->id) : $old;
            // Pindah tanpa mengganti nama: nama yang ada tetap harus bebas di outlet tujuan.
            if ($moving && ! array_key_exists('name', $data)) {
                $this->assertNameFree($targetOutletId, $old, $device->id);
            }

            if ($new !== $old) {
                $device->forceFill(['name' => $new])->save();
                $this->log->log($by->id, 'device_renamed', 'Device', $device->id, "Terminal {$old} diganti namanya menjadi {$new}", ['name' => $old], ['name' => $new]);
            }

            if ($moving) {
                $codes = Outlet::query()->whereIn('id', [$fromOutletId, $targetOutletId])->pluck('code', 'id');
                $device->setAttribute('outlet_id', $targetOutletId);
                $device->save();
                $this->log->log(
                    $by->id, 'device_moved', 'Device', $device->id,
                    "Terminal {$device->name} dipindah dari outlet ".($codes[$fromOutletId] ?? $fromOutletId).' ke '.($codes[$targetOutletId] ?? $targetOutletId),
                    ['outlet_id' => $fromOutletId], ['outlet_id' => $targetOutletId],
                );
            }

            return $device;
        });
    }

    /**
     * 079 — aktifkan lagi terminal yang dinonaktifkan. Nama harus masih bebas di antara terminal AKTIF outletnya (nama dilepas
     * saat dicabut, jadi bisa sudah dipakai terminal lain → 422 `name`). Terminal yang sudah aktif: tidak ada perubahan dan tidak
     * ada log. Terminal milik outlet nonaktif tidak sampai ke sini: OutletScope menyembunyikannya (404).
     */
    public function reactivate(Device $device, User $by): Device
    {
        return DB::transaction(function () use ($device, $by) {
            if ($device->revoked_at === null) {
                return $device;
            }

            $this->assertNameFree((int) $device->outlet_id, $device->name, $device->id);

            $device->forceFill(['revoked_at' => null])->save();
            $this->log->log($by->id, 'device_reactivated', 'Device', $device->id, "Terminal {$device->name} diaktifkan lagi");

            return $device;
        });
    }

    /**
     * 079 — hapus permanen, hanya terminal yang belum pernah dipakai. FK `orders.device_id` dan `cashier_sessions.device_id`
     * berupa SET NULL, jadi menghapus terminal yang punya riwayat akan MELEPAS riwayat itu dari terminalnya tanpa suara —
     * penjaganya ada di sini (409 `device`), bukan di database. Tabel `terminal_counters` ikut terhapus (CASCADE).
     */
    public function deleteIfUnused(Device $device, User $by): void
    {
        DB::transaction(function () use ($device, $by) {
            // Tanpa global scope: riwayat di outlet/mode mana pun tetap menghalangi (FK tidak peduli scope).
            $used = Order::withoutGlobalScopes()->where('device_id', $device->id)->exists()
                || CashierSession::withoutGlobalScopes()->where('device_id', $device->id)->exists();
            if ($used) {
                throw ValidationException::withMessages(['device' => [__('devices.in_use')]])->status(409);
            }

            $this->log->log($by->id, 'device_deleted', 'Device', $device->id, "Terminal {$device->name} dihapus permanen");
            $device->delete();
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

    /**
     * 079 — aksi massal (aktifkan, nonaktifkan, hapus) untuk halaman Terminal. TIDAK semua-atau-tidak-sama-sekali: tiap
     * terminal memakai jalur tunggalnya sendiri (`reactivate`, `revoke`, `deleteIfUnused`), masing-masing dalam transaksi dan
     * log aktivitasnya sendiri, sehingga satu terminal yang punya riwayat tidak menahan sepuluh lainnya. Aturan hapus dan
     * aktifkan TIDAK disalin di sini. `Device::query()` ber-OutletScope: id di luar outlet yang boleh dilihat pengguna (atau
     * tak ada) tidak pernah ditemukan dan dilaporkan `not_found`, tanpa membedakan keduanya.
     *
     * @param  list<int>  $ids
     * @param  'activate'|'deactivate'|'delete'  $action
     * @return list<array{id: int, name: ?string, status: 'changed'|'skipped'|'refused'|'not_found', reason: ?string}>
     */
    public function bulk(array $ids, string $action, User $by): array
    {
        $found = Device::query()->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(function (int $id) use ($found, $action, $by) {
            $device = $found->get($id);
            if ($device === null) {
                return ['id' => $id, 'name' => null, 'status' => 'not_found', 'reason' => __('devices.bulk_not_found')];
            }

            $row = fn (string $status, ?string $reason = null) => ['id' => $id, 'name' => $device->name, 'status' => $status, 'reason' => $reason];
            $revoked = $device->revoked_at !== null;

            if (($action === 'activate' && ! $revoked) || ($action === 'deactivate' && $revoked)) {
                return $row('skipped');
            }

            try {
                match ($action) {
                    'activate' => $this->reactivate($device, $by),
                    'deactivate' => $this->revoke($device, $by),
                    'delete' => $this->deleteIfUnused($device, $by),
                };
            } catch (ValidationException $e) {
                return $row('refused', collect($e->errors())->flatten()->first());
            }

            return $row('changed');
        })->values()->all();
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

