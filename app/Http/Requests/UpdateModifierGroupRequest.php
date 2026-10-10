<?php

namespace App\Http\Requests;

/** 028 US3 — semua field opsional; `options` bila hadir = penggantian penuh menurut id. */
class UpdateModifierGroupRequest extends StoreModifierGroupRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'] = ['sometimes', 'required', 'string', 'max:100'];
        $rules['selection_type'] = ['sometimes', 'in:single,multiple'];
        $rules['options'] = ['sometimes', 'array', 'min:1', 'max:100'];

        return $rules;
    }
}
