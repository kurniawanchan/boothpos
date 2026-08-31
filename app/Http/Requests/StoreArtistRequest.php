<?php

namespace App\Http\Requests;

use App\Models\Artist;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreArtistRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Otorisasi objek dicek via Policy di controller (Gate::authorize),
        // bukan di sini, agar konsisten dengan update/delete yang butuh
        // instance model. authorize() di sini tetap true; pemeriksaan
        // peran tetap terjadi sebelum method controller dijalankan.
        return $this->user()?->can('create', Artist::class) ?? false;
    }

    /**
     * WAJIB ada sejak ArtistPolicy::create mengembalikan Response (bukan
     * bool polos) untuk membedakan dua alasan penolakan berbeda (peran
     * salah vs kuota lisensi Pro habis). Tanpa override ini, FormRequest
     * default membuang pesan kustom dari Response::deny() dan pengguna
     * hanya melihat 403 generik "This action is unauthorized."
     */
    protected function failedAuthorization(): void
    {
        $response = Gate::inspect('create', Artist::class);

        throw new AuthorizationException($response->message() ?: 'Tidak berhak.');
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'size:3',
                'alpha',
                Rule::unique('artists', 'code')->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:100'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'contact_email' => ['nullable', 'email', 'max:100'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
