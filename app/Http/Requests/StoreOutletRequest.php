<?php

namespace App\Http\Requests;

use App\Models\Outlet;
use App\Rules\OutletCodeRule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreOutletRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Otorisasi objek dicek via Policy di controller (Gate::authorize),
        // bukan di sini, agar konsisten dengan update/delete yang butuh
        // instance model. authorize() di sini tetap true; pemeriksaan
        // peran tetap terjadi sebelum method controller dijalankan.
        return $this->user()?->can('create', Outlet::class) ?? false;
    }

    /**
     * WAJIB ada sejak OutletPolicy::create mengembalikan Response (bukan
     * bool polos) untuk membedakan dua alasan penolakan berbeda (peran
     * salah vs kuota lisensi Pro habis). Tanpa override ini, FormRequest
     * default membuang pesan kustom dari Response::deny() dan pengguna
     * hanya melihat 403 generik "This action is unauthorized."
     */
    protected function failedAuthorization(): void
    {
        $response = Gate::inspect('create', Outlet::class);

        throw new AuthorizationException($response->message() ?: 'Tidak berhak.');
    }

    public function rules(): array
    {
        return [
            // 079 — 3–5 huruf/angka, unik tanpa beda huruf; satu definisi di OutletCodeRule.
            'code' => ['required', 'string', new OutletCodeRule],
            'name' => ['required', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'contact_email' => ['nullable', 'email', 'max:100'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
