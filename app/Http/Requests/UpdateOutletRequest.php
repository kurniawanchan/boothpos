<?php

namespace App\Http\Requests;

use App\Rules\OutletCodeRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOutletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('outlet')) ?? false;
    }

    public function rules(): array
    {
        return [
            // 079 — kode boleh disunting (dulu `prohibited`, F19.4). Aturannya sama dengan saat membuat; outlet ini
            // sendiri dikecualikan dari cek duplikat. Kode produk/SKU lama TIDAK ikut berubah (tertanam saat dibuat).
            'code' => ['sometimes', 'required', 'string', new OutletCodeRule($this->route('outlet')?->id)],
            'name' => ['required', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'contact_email' => ['nullable', 'email', 'max:100'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
