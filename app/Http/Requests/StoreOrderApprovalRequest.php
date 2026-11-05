<?php

namespace App\Http\Requests;

use App\Services\OrderApprovalService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 059 — bentuk permintaan persetujuan. Hak, kepemilikan seller, status penjualan, kesegaran hash, dan jenis
 * berkas sebenarnya diputuskan OrderApprovalService (di bawah kunci baris); di sini hanya bentuknya.
 */
class StoreOrderApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // hak per-seller dinilai service → 403 ber-kode `approval_not_allowed`
    }

    public function rules(): array
    {
        return [
            'artist_id' => ['required', 'integer'],
            'method' => ['required', 'in:draw,upload,stamp'],
            'items_hash' => ['required', 'string', 'max:80'],
            'image' => ['nullable', 'file', 'mimes:png,jpg,jpeg', 'max:'.OrderApprovalService::MAX_IMAGE_KILOBYTES],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $method = $this->input('method');
            if (in_array($method, ['draw', 'upload'], true) && ! $this->hasFile('image')) {
                $v->errors()->add('image', __('approvals.image_required'));
            }
            if ($method === 'stamp' && $this->hasFile('image')) {
                $v->errors()->add('image', __('approvals.image_not_allowed_for_stamp'));
            }
        });
    }
}
