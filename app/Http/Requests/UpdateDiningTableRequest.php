<?php

namespace App\Http\Requests;

/**
 * 028 US1 — semua field opsional pada update; keunikan (area, nama)
 * dihitung dari nilai akhir (input ATAU nilai meja saat ini).
 */
class UpdateDiningTableRequest extends StoreDiningTableRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:60'],
            'area' => ['sometimes', 'nullable', 'string', 'max:60'],
            'seats' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'display_order' => ['sometimes', 'integer', 'min:-32768', 'max:32767'],
            'is_active' => ['sometimes', 'boolean'],
            // 071 — pindah outlet (hanya pengguna dengan izin outlet tujuan; dicek di controller bersama OutletMoveGuard).
            'outlet_id' => ['sometimes', 'integer', 'exists:outlets,id'],
        ];
    }
}
