<?php

namespace App\Http\Requests;

use App\Models\Setting;
use App\Support\AppName;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Endpoint admin untuk `Setting::updateOrCreate` (F14.1/F14.3, dan juga
 * satu-satunya jalur mengubah `multi_outlet_enabled` — lihat README bagian
 * "Lisensi Pro vs Master", yang sebelumnya menyebut ini sebagai gap).
 *
 * Bentuk request BULK (array of {key, value, type?, group?}), bukan satu
 * key per request — layar pengaturan biasanya menyimpan beberapa field
 * sekaligus (nama toko, kontak, format struk), dan `settings` memang
 * dirancang sebagai tabel key-value generik, bukan satu resource per baris.
 */
class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', Setting::class) ?? false;
    }

    protected function failedAuthorization(): void
    {
        $response = Gate::inspect('update', Setting::class);

        throw new AuthorizationException($response->message() ?: 'Tidak berhak mengubah pengaturan.');
    }

    public function rules(): array
    {
        return [
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.key' => ['required', 'string', 'max:100'],
            // 'present' (bukan 'required') — value BOLEH null (mis. mengosongkan
            // logo/kontak), tapi kuncinya harus dikirim eksplisit supaya tidak
            // ambigu dengan field yang memang tidak disertakan sama sekali.
            //
            // FR-018 (US3, profil toko) menambah SATU pengecualian nyata di
            // sini: karena 'settings' adalah array bulk berisi key APAPUN,
            // tidak ada field 'store_contact_email' tersendiri untuk digantungi
            // rule 'email' bawaan Laravel — jadi validasi formatnya ditegakkan
            // lewat closure yang membaca key baris yang sama, bukan menambah
            // field terpisah. Tidak ada validasi format serupa untuk key lain
            // (mis. 'multi_outlet_enabled' hanya divalidasi lewat rule 'type'
            // di bawah) karena hanya FR-018 yang secara eksplisit meminta ini.
            'settings.*.value' => [
                'present',
                function ($attribute, $value, $fail) {
                    $index = explode('.', $attribute)[1] ?? null;
                    $key = $this->input("settings.{$index}.key");

                    if ($key === 'store_contact_email' && $value !== null && $value !== '') {
                        $validator = Validator::make(['email' => $value], ['email' => ['email']]);

                        if ($validator->fails()) {
                            $fail('Format email kontak toko tidak valid.');
                        }
                    }

                    // 003-seed-demo-live (FR-004) — 'system_mode' adalah satu
                    // baris settings lagi, sama seperti 'multi_outlet_enabled',
                    // jadi divalidasi lewat closure yang sama alih-alih endpoint
                    // baru (lihat contracts/settings-system-mode.md).
                    if ($key === 'system_mode' && ! in_array($value, ['demo', 'live'], true)) {
                        $fail('Mode sistem harus salah satu dari: demo, live.');
                    }

                    // Nama aplikasi ditampilkan di sidebar dan dokumen — dibatasi
                    // panjangnya supaya tidak merusak tata letak. Kosong BOLEH
                    // (= kembali ke default, lihat App\Support\AppName).
                    if ($key === 'app_name' && is_string($value) && mb_strlen($value) > AppName::MAX_LENGTH) {
                        $fail('Nama aplikasi maksimal '.AppName::MAX_LENGTH.' karakter.');
                    }

                    // 043 — pengaturan menu (rilis, ambang persetujuan, pembulatan bawaan).
                    if ($key === 'menu_release_enabled' && filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) {
                        $fail('Pengaturan rilis menu harus berupa true atau false.');
                    }
                    if ($key === 'price_approval_threshold_pct' && (! is_numeric($value) || (float) $value < 0 || (float) $value > 100)) {
                        $fail('Ambang persetujuan harga harus berupa angka 0–100.');
                    }
                    if ($key === 'price_rounding_default' && ! in_array((string) $value, ['none', '500'], true)) {
                        $fail('Pembulatan harga bawaan harus none atau 500.');
                    }

                    // 006-purchase-order-and-ops (US6) — pola closure yang
                    // sama seperti store_contact_email/system_mode di atas:
                    // theme_accent_color adalah baris settings biasa, tidak
                    // butuh endpoint baru, hanya validasi formatnya sendiri.
                    // 042-dashboard-revamp — hari bisnis. Pola closure yang sama: baris settings
                    // biasa, tanpa endpoint baru. Nilai ngawur akan membuat BusinessDay jatuh ke
                    // default, jadi ditolak di sini agar admin tahu, bukan diam-diam diabaikan.
                    if ($key === 'store_timezone' && ! in_array($value, \DateTimeZone::listIdentifiers(), true)) {
                        $fail('Zona waktu toko tidak dikenal (contoh: Asia/Jakarta).');
                    }
                    if ($key === 'business_day_cutoff' && ! (is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value))) {
                        $fail('Jam batas hari harus berformat HH:MM (00:00–23:59).');
                    }
                    // 083 — pajak: tarif 0–100 maksimal 2 desimal (nilai ngawur dulu diam-diam jatuh ke 0 di PosSettings),
                    // label maksimal 30 karakter (dipotong tampilan, jadi ditolak di sini), tampil/sembunyi harus boolean.
                    if ($key === 'tax_rate' && (! is_numeric($value) || (float) $value < 0 || (float) $value > 100 || round((float) $value, 2) != (float) $value)) {
                        $fail('Tarif pajak harus berupa angka 0–100 dengan maksimal 2 desimal.');
                    }
                    if ($key === 'tax_label' && is_string($value) && mb_strlen(trim($value)) > 30) {
                        $fail('Label pajak maksimal 30 karakter.');
                    }
                    if ($key === 'tax_show' && filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) {
                        $fail('Pengaturan tampil pajak harus berupa true atau false.');
                    }
                    if ($key === 'cash_difference_tolerance' && (! is_numeric($value) || (float) $value < 0)) {
                        $fail('Toleransi selisih kas harus berupa angka ≥ 0.');
                    }
                    if ($key === 'device_offline_after_minutes' && (! is_numeric($value) || (int) $value != $value || (int) $value < 1 || (int) $value > 1440)) {
                        $fail('Batas perangkat offline harus bilangan bulat 1–1440 menit.');
                    }

                    if ($key === 'theme_accent_color' && $value !== null && $value !== '' && ! preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                        $fail('Warna tema harus berupa kode hex 6 digit, mis. #2f9e6e.');
                    }
                },
            ],
            'settings.*.type' => ['sometimes', 'in:string,integer,decimal,boolean,json'],
            'settings.*.group' => ['sometimes', 'string', 'max:50'],
        ];
    }
}
