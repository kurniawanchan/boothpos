<?php

namespace App\Http\Requests;

use App\Models\Setting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

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
            'settings.*.value' => ['present'],
            'settings.*.type' => ['sometimes', 'in:string,integer,decimal,boolean,json'],
            'settings.*.group' => ['sometimes', 'string', 'max:50'],
        ];
    }
}
