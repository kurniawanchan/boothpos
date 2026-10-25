<?php

namespace App\Http\Requests;

/** 028 US4 — semua field opsional pada update. */
class UpdateStationRequest extends StoreStationRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:60'],
            'display_order' => ['sometimes', 'integer', 'min:-32768', 'max:32767'],
            'is_active' => ['sometimes', 'boolean'],
            // 071 — pindah outlet (izin outlet tujuan dan OutletMoveGuard dicek di controller).
            'outlet_id' => ['sometimes', 'integer', 'exists:outlets,id'],
        ];
    }
}
