<?php

namespace App\Http\Requests;

use App\Support\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;

/** 043 — mendiskontinu item: izin `menu.item.manage`, alasan wajib. */
class DiscontinueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(PermissionCatalog::ITEM_MANAGE) ?? false;
    }

    public function rules(): array
    {
        return [
            'reason_code_id' => ['required', 'integer'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
