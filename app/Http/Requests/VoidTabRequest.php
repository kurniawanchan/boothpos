<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 028 US6 — membatalkan seluruh tab: owner/admin saja (tingkat yang sama dengan void penjualan), alasan wajib. */
class VoidTabRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return ($user?->canAccessMenu('floor') ?? false) && $user->isOwnerOrAdmin();
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:255']];
    }
}
