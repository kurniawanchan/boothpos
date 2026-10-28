<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 079 — aksi massal halaman Terminal. Hanya owner/admin, sama dengan rute terminal lainnya. Outlet tidak pernah dibaca dari
 * tubuh: `ids` hanya menunjuk terminal, dan server membatasinya ke outlet yang boleh dilihat pengguna (OutletScope).
 * Batas 100 = ukuran halaman terbesar daftar, sehingga satu permintaan tidak melampaui satu halaman.
 */
class BulkDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isOwnerOrAdmin();
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:activate,deactivate,delete'],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
