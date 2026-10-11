<?php

namespace App\Http\Requests;

use App\Support\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;

/** 043 — menerbitkan rilis menu: izin `menu.release.publish`. */
class PublishReleaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(PermissionCatalog::RELEASE_PUBLISH) ?? false;
    }

    public function rules(): array
    {
        return [
            'publish_at' => ['sometimes', 'nullable', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'change_ids' => ['sometimes', 'array', 'min:1'],
            'change_ids.*' => ['integer'],
        ];
    }
}
