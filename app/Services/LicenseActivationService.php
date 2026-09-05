<?php

namespace App\Services;

use App\Models\LicenseActivation;
use App\Support\LicenseSigning;
use App\Support\MachineFingerprint;
use Illuminate\Support\Facades\Hash;

/**
 * 018-license-activation — satu jalur sah untuk memverifikasi,
 * mengaktivasi, dan memeriksa status aktivasi instalasi ini. Business
 * logic-nya (verifikasi tanda tangan, fingerprint mesin, baca/tulis
 * baris ter-hash) genuinely non-trivial (Constitution I), dipisahkan
 * dari middleware/controller yang memanggilnya.
 */
class LicenseActivationService
{
    /**
     * FR-006 — gagal TERTUTUP: hanya true bila baris ada DAN fingerprint
     * mesin saat ini cocok dengan hash yang tersimpan. Exception apa pun
     * (mesin tidak dikenali, dsb.) HARUS resolve ke false, tidak pernah
     * sebaliknya.
     */
    public function isActivated(): bool
    {
        $activation = LicenseActivation::query()->first();
        if (! $activation) {
            return false;
        }

        try {
            $currentFingerprint = MachineFingerprint::current();
        } catch (\Throwable) {
            return false;
        }

        return Hash::check($currentFingerprint, $activation->machine_fingerprint_hash);
    }

    /**
     * Melempar InvalidArgumentException (dari LicenseSigning) bila kunci
     * tidak valid — pemanggil (LicenseController) yang memetakannya ke
     * respons 422 generik (FR-003).
     *
     * data-model.md — SENGAJA SELALU meng-upsert baris tunggal ke
     * fingerprint mesin SAAT INI, terlepas dari baris apa yang sudah ada
     * sebelumnya (mesin mana pun ia terikat). Ini yang membuat aktivasi
     * ulang pasca pergantian mesin toko yang sah menjadi swalayan
     * (research.md R8) — sekaligus, secara jujur, batasan yang sama
     * membuat kunci yang sama bisa dipakai ulang di instalasi lain yang
     * benar-benar baru (tidak terdeteksi tanpa server pusat).
     */
    public function activate(string $licenseKey): LicenseActivation
    {
        $payload = LicenseSigning::decodeAndVerify($licenseKey);

        $fingerprint = MachineFingerprint::current();

        LicenseActivation::query()->delete();

        return LicenseActivation::create([
            'license_id' => $payload['license_id'],
            'issued_to' => $payload['issued_to'],
            'machine_fingerprint_hash' => Hash::make($fingerprint),
            'activated_at' => now(),
        ]);
    }
}
