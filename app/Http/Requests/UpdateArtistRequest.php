<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateArtistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('artist')) ?? false;
    }

    public function rules(): array
    {
        return [
            // 'code' sengaja TIDAK ada di rule sebagai field yang bisa
            // diisi. 'prohibited' membuat request eksplisit gagal (422)
            // bila klien mencoba mengirim 'code', alih-alih diam-diam
            // mengabaikannya — sesuai keputusan desain skema #5 (PRD):
            // code permanen setelah dibuat.
            'code' => ['prohibited'],
            'name' => ['required', 'string', 'max:100'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'contact_email' => ['nullable', 'email', 'max:100'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.prohibited' => __('master_data.artist_code_permanent'),
        ];
    }
}
