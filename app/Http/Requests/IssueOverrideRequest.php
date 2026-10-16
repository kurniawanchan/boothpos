<?php

namespace App\Http\Requests;

use App\Support\OverrideSubject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 048 / S3 — body `POST /overrides` (kontrak yang disepakati): `subject` = bidang BODY aksi yang dijaga saja,
 * tanpa parameter path. Server menghitung hash-nya sendiri; klien tidak pernah mengirim hash.
 */
class IssueOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(OverrideSubject::actions())],
            'subject' => ['present', 'array'],
            'approver_user_id' => ['required', 'integer'],
            'pin' => ['required', 'digits:6'],
        ];
    }
}
