<?php

namespace App\Http\Requests;

use App\Models\Setting;
use App\Support\AppName;
use App\Support\CustomerCommerceGate;
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
            // 057 — kunci huruf kecil/angka/underscore saja: pencarian DB tak peka huruf besar-kecil, jadi `APP_NAME` akan
            // menimpa `app_name` sambil lolos dari daftar kunci branding di controller.
            'settings.*.key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
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

                    // Nama aplikasi ditampilkan di sidebar dan dokumen — dibatasi
                    // panjangnya supaya tidak merusak tata letak. Kosong BOLEH
                    // (= kembali ke default, lihat App\Support\AppName).
                    if ($key === 'app_name' && is_string($value) && mb_strlen($value) > AppName::MAX_LENGTH) {
                        $fail('Nama aplikasi maksimal '.AppName::MAX_LENGTH.' karakter.');
                    }

                    // 006-purchase-order-and-ops (US6) — pola closure yang
                    // sama seperti store_contact_email/system_mode di atas:
                    // theme_accent_color adalah baris settings biasa, tidak
                    // butuh endpoint baru, hanya validasi formatnya sendiri.
                    if ($key === 'theme_accent_color' && $value !== null && $value !== '' && ! preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                        $fail('Warna tema harus berupa kode hex 6 digit, mis. #2f9e6e.');
                    }

                    // Customer commerce — saklar toko online. Hanya nilai boolean yang jelas: tanpa cabang ini
                    // kunci apa pun diterima apa adanya, jadi nilai seperti 'banana' ikut tersimpan dan arti
                    // "aktif/nonaktif"-nya bergantung pada cara baca. Null ditolak: mematikan = false eksplisit.
                    if ($key === CustomerCommerceGate::KEY && ! in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false'], true)) {
                        $fail(__('customer_commerce.settings.enabled_must_be_boolean'));
                    }

                    // Customer commerce — teks petunjuk ambil di tempat / kurir yang ditampilkan ke PELANGGAN di
                    // aplikasi: teks biasa yang pendek. Tag HTML dan karakter kontrol ditolak supaya isinya tidak
                    // pernah bisa ditafsirkan sebagai markup oleh klien mana pun. Kosong (null) = tanpa petunjuk.
                    if (in_array($key, CustomerCommerceGate::INSTRUCTION_KEYS, true) && $value !== null && ! $this->isShortPlainText($value)) {
                        $fail(__('customer_commerce.settings.instructions_must_be_plain_text', ['max' => CustomerCommerceGate::INSTRUCTIONS_MAX_LENGTH]));
                    }
                },
            ],
            'settings.*.type' => ['sometimes', 'in:string,integer,decimal,boolean,json'],
            'settings.*.group' => ['sometimes', 'string', 'max:50'],
        ];
    }

    /**
     * Customer commerce — teks biasa: string, paling banyak INSTRUCTIONS_MAX_LENGTH karakter, tanpa tag
     * HTML dan tanpa karakter kontrol selain baris baru/tab. Tanda `<` atau `&` lepas ("Harga < 50rb")
     * tetap boleh; yang ditolak adalah bentuk tag (`<b>`, `</a>`, `<script …>`).
     */
    private function isShortPlainText(mixed $value): bool
    {
        return is_string($value)
            && mb_strlen($value) <= CustomerCommerceGate::INSTRUCTIONS_MAX_LENGTH
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) !== 1
            && preg_match('/<\s*\/?\s*[a-zA-Z!][^>]*>/', $value) !== 1;
    }
}
