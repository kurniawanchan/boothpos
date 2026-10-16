<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 048 / T060 — body `POST /orders/{id}/reprints`. `copy_no` = salinan yang akan dicetak (dibaca klien dari
 * salinan berikutnya); server memastikannya masih benar, supaya persetujuan tidak dipakai untuk salinan lain.
 */
class ReprintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('sales') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'copy_no' => ['required', 'integer', 'min:2'],
            'override_token' => ['nullable', 'string', 'max:120'],
        ];
    }
}
