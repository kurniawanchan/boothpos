<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 090 / US5 — ganti Dine-In/Take Away tab terbuka. Hak akses sama dengan mengubah tab: menu `floor`. */
class UpdateTabServiceTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('floor') ?? false;
    }

    public function rules(): array
    {
        return ['service_type' => ['required', 'in:dine_in,takeaway']];
    }
}
