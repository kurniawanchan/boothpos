<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 028 US5 — menggabungkan tab SUMBER ke tab pada URL (target). */
class MergeTabRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('floor') ?? false;
    }

    public function rules(): array
    {
        return ['source_tab_id' => ['required', 'integer']];
    }
}
