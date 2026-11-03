<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DiningTable */
class DiningTableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // 045 Stage 2 — outlet pemilik. 071: bisa dipindah lewat PUT (OutletMoveGuard); sebelumnya tetap sejak dibuat.
            'outlet_id' => $this->outlet_id,
            'area' => $this->area ?? '',
            'seats' => $this->seats,
            'display_order' => $this->display_order,
            'is_active' => $this->is_active,
            // Diisi withExists('openTab as has_open_tab') pada daftar; false bila tidak dimuat.
            'has_open_tab' => (bool) ($this->has_open_tab ?? false),
            // 090 — apakah QR bisa dibuat (alamat dasar yang bisa dijangkau ponsel pelanggan tersedia). BUKAN token.
            // Dihitung SEKALI per permintaan (Setting::get kosong tidak ter-cache → satu query per baris bila dihitung per meja).
            'qr_ready' => $request->attributes->get('qr_ready') ?? tap(\App\Support\SelfServiceSettings::baseUrl($request->getSchemeAndHttpHost()) !== null, fn ($v) => $request->attributes->set('qr_ready', $v)),
        ];
    }
}
