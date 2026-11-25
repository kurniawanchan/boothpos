<?php

namespace App\Http\Requests;

use App\Models\Outlet;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 094 / kontrak A1 — login aplikasi manajemen. Tanpa token (belum ada pengguna), jadi authorize() selalu true; penolakan peran
 * dan outlet dilakukan SETELAH kredensial benar di ManagerSessionController supaya 403 tidak membocorkan keberadaan akun.
 */
class ManagerSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string'],
            // Outlet harus ada, aktif, dan milik mode data yang sedang berlaku. Outlet::query() memakai DataModeScope; aturan
            // `exists` biasa akan menerima outlet mode lain.
            'outlet_id' => ['required', 'integer', function (string $attribute, mixed $value, Closure $fail) {
                if (! Outlet::query()->whereKey((int) $value)->where('is_active', true)->exists()) {
                    $fail(__('validation.exists', ['attribute' => 'outlet']));
                }
            }],
        ];
    }
}
