<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 048 / T064 — heartbeat terminal tiap 60 dtk. `queue_depth` divalidasi saja: server tidak menyimpannya
 * (tanpa tabel baru; antrean sinkron adalah B16 di luar cakupan).
 */
class DeviceHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'app_version' => ['required', 'string', 'max:40'],
            // Epoch milidetik jam perangkat; batas atas = 1 Jan 2100 (menolak nilai salah ketik).
            'clock_ms' => ['required', 'integer', 'min:0', 'max:4102444800000'],
            'queue_depth' => ['required', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
