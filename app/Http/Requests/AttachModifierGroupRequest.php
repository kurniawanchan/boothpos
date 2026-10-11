<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 028 US3 — melampirkan grup ke produk/kategori (penggantian penuh). Keberadaan id dicek di service. */
class AttachModifierGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('dining_setup') ?? false;
    }

    public function rules(): array
    {
        return [
            'product_ids' => ['present', 'array'],
            'product_ids.*' => ['integer'],
            'category_ids' => ['present', 'array'],
            'category_ids.*' => ['integer'],
        ];
    }
}
