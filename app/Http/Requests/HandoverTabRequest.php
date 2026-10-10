<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 028 US2 — serah-terima tab ke shift lain: owner/admin saja (tingkat yang sama dengan void). */
class HandoverTabRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return ($user?->canAccessMenu('floor') ?? false) && $user->isOwnerOrAdmin();
    }

    public function rules(): array
    {
        return ['session_id' => ['required', 'integer']];
    }
}
