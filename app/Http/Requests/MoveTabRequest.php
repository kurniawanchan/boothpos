<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 028 US5 — memindahkan tab ke meja lain. Meja dicari lewat Eloquent di service (404 lintas-mode). */
class MoveTabRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('floor') ?? false;
    }

    public function rules(): array
    {
        return ['table_id' => ['required', 'integer']];
    }
}
