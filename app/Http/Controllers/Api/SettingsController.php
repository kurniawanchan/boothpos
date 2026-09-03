<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSettingsRequest;
use App\Http\Resources\SettingResource;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\ImageUploadService;
use App\Support\LicenseGate;
use App\Support\ModeGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private ImageUploadService $imageUploadService,
    ) {}

    /**
     * Dibaca UI untuk sembunyikan/tampilkan tombol "Tambah Artist", BUKAN
     * sumber otorisasi. Penegakan sesungguhnya tetap di ArtistPolicy —
     * lihat komentar di endpoint ini pada openapi-pos-mvp.yaml.
     *
     * `system_mode` (003-seed-demo-live, FR-005) sengaja TIDAK digerbang
     * policy tambahan — endpoint ini sudah terbuka untuk semua role yang
     * login, dan status mode harus terlihat semua orang, bukan cuma
     * owner/admin (yang berwenang MENGUBAHNYA tetap hanya lewat
     * PUT /settings, digerbang SettingPolicy seperti biasa).
     */
    public function features(): JsonResponse
    {
        return response()->json([
            'multi_artist_enabled' => LicenseGate::multiArtistEnabled(),
            'artist_count' => LicenseGate::activeArtistCount(),
            'artist_limit_reached' => LicenseGate::artistLimitReached(),
            'system_mode' => ModeGate::current(),
        ]);
    }

    /**
     * F14 — daftar seluruh pengaturan (nama toko, kontak, format struk,
     * flag lisensi, dst). Owner/admin saja — lihat SettingPolicy. Tabel
     * `payment_channels` (nomor rekening) sengaja TIDAK ikut di sini,
     * itu resource terpisah dengan penyamaran nomor rekeningnya sendiri.
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Setting::class);

        $settings = Setting::query()->orderBy('group')->orderBy('key')->get();

        return response()->json(['data' => SettingResource::collection($settings)]);
    }

    /**
     * F14.1/F14.3 dan satu-satunya jalur admin untuk mengubah
     * `multi_artist_enabled` (upgrade Pro -> Master) — sebelumnya hanya
     * bisa lewat Setting::updateOrCreate() langsung (tinker/seeder), belum
     * ada endpoint. Lihat README bagian "Lisensi Pro vs Master".
     *
     * Bentuk BULK: satu request bisa mengubah beberapa key sekaligus,
     * sesuai kebutuhan layar pengaturan yang menyimpan banyak field dalam
     * satu submit.
     */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $items = $request->validated('settings');

        $updated = DB::transaction(function () use ($items, $request) {
            $results = [];

            foreach ($items as $item) {
                $existing = Setting::where('key', $item['key'])->first();

                $type = $item['type'] ?? $existing->type ?? 'string';
                $group = $item['group'] ?? $existing->group ?? 'general';

                $oldValues = $existing
                    ? ['value' => $existing->value, 'type' => $existing->type, 'group' => $existing->group]
                    : null;

                $setting = Setting::updateOrCreate(
                    ['key' => $item['key']],
                    ['value' => $this->normalizeValueForStorage($item['value'], $type), 'type' => $type, 'group' => $group]
                );

                // F13.4 — perubahan pengaturan adalah tindakan sensitif
                // (termasuk toggle lisensi Pro/Master), dicatat per key
                // di dalam transaksi yang sama dengan penyimpanannya.
                $this->activityLogger->log(
                    userId: $request->user()?->id,
                    action: 'updated',
                    entityType: 'Setting',
                    entityId: $setting->id,
                    description: "Mengubah pengaturan '{$setting->key}'.",
                    oldValues: $oldValues,
                    newValues: ['value' => $setting->value, 'type' => $setting->type, 'group' => $setting->group],
                );

                $results[] = $setting;
            }

            return $results;
        });

        return response()->json(['data' => SettingResource::collection(collect($updated))]);
    }

    /**
     * T045 (US3, FR-018) — mengikuti pola CategoryController::uploadImage()
     * persis: endpoint terpisah untuk logo toko, BUKAN lewat body JSON
     * `PUT /settings` (lihat research.md Decision 3 — multipart butuh
     * endpoint sendiri, generic bulk-update tidak menerima file upload).
     * `store_logo_path` tetap baris `settings` biasa; hanya jalur
     * penulisannya yang berbeda dari key-key lain.
     */
    public function uploadStoreLogo(Request $request): JsonResponse
    {
        $this->authorize('update', Setting::class);

        $validated = $request->validate([
            'image' => [
                'required',
                'file',
                Rule::file()->max(ImageUploadService::MAX_KILOBYTES)->rules(['mimes:jpeg,png']),
            ],
        ]);

        $existing = Setting::where('key', 'store_logo_path')->first();
        $oldPath = $existing?->value;

        $newPath = $this->imageUploadService->store($validated['image'], 'store-logo');

        $setting = DB::transaction(function () use ($newPath, $existing, $request) {
            $oldValues = $existing
                ? ['value' => $existing->value, 'type' => $existing->type, 'group' => $existing->group]
                : null;

            $setting = Setting::updateOrCreate(
                ['key' => 'store_logo_path'],
                ['value' => $newPath, 'type' => 'string', 'group' => 'receipt'],
            );

            // F13.4 — sama seperti update() di atas, dicatat di dalam
            // transaksi yang sama dengan penyimpanannya.
            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'updated',
                entityType: 'Setting',
                entityId: $setting->id,
                description: "Mengubah pengaturan 'store_logo_path'.",
                oldValues: $oldValues,
                newValues: ['value' => $setting->value, 'type' => $setting->type, 'group' => $setting->group],
            );

            return $setting;
        });

        // File lama dihapus SETELAH transaksi commit — kalau rollback terjadi
        // (mis. gagal menulis log), berkas lama masih ada untuk dirujuk.
        $this->imageUploadService->delete($oldPath);

        return response()->json(['data' => new SettingResource($setting)]);
    }

    /**
     * Kolom `value` di tabel `settings` bertipe TEXT — disimpan sebagai
     * string mentah selalu, terlepas dari `type`. Interpretasinya (mis.
     * filter_var untuk boolean di LicenseGate) terjadi di sisi pembaca,
     * bukan di sini. Ditangani khusus supaya klien boleh mengirim JSON
     * asli (true/false, angka, objek) tanpa harus tahu detail penyimpanan.
     */
    private function normalizeValueForStorage(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($type === 'json') {
            return is_string($value) ? $value : json_encode($value);
        }

        if ($type === 'boolean' && is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
