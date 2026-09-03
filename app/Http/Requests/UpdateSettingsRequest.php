<?php

namespace App\Http\Requests;

use App\Models\Setting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Endpoint admin untuk `Setting::updateOrCreate` (F14.1/F14.3, dan juga
 * satu-satunya jalur mengubah `multi_artist_enabled` — lihat README bagian
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
            // (mis. 'multi_artist_enabled' hanya divalidasi lewat rule 'type'
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
                    // baris settings lagi, sama seperti 'multi_artist_enabled',
                    // jadi divalidasi lewat closure yang sama alih-alih endpoint
                    // baru (lihat contracts/settings-system-mode.md).
                    if ($key === 'system_mode' && ! in_array($value, ['demo', 'live'], true)) {
                        $fail('Mode sistem harus salah satu dari: demo, live.');
                    }
                },
            ],
            'settings.*.type' => ['sometimes', 'in:string,integer,decimal,boolean,json'],
            'settings.*.group' => ['sometimes', 'string', 'max:50'],
        ];
    }
}
