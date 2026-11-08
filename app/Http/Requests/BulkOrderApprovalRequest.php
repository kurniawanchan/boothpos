<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 064 — bentuk permintaan persetujuan massal (pratinjau DAN terapkan). `order_ids` dan `plan` datang sebagai SATU
 * string JSON per kolom (multipart): array bersarang 200 penjualan × seller melewati `max_input_vars` PHP (1000)
 * dan memotong diam-diam. Hak dan kelayakan per penjualan BUKAN di sini — dinilai OrderApprovalService per penjualan
 * (yang tak layak dilewati, bukan membuat permintaan gagal); di sini hanya bentuk, batas, dan metode.
 */
class BulkOrderApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['order_ids', 'plan'] as $field) {
            $raw = $this->input($field);
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                // JSON rusak → dibiarkan string supaya aturan `array` menolaknya dengan 422 (bukan 500).
                $merge[$field] = json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
            }
        }
        $this->merge($merge);
    }

    public function rules(): array
    {
        $isApply = ! str_ends_with($this->path(), '/preview');

        $rules = [
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', 'distinct'],
        ];

        if ($isApply) {
            $rules += [
                'method' => ['required', 'in:draw,upload,stamp'],
                'plan' => ['present', 'array'], // [] sah: semua penjualan hanya akan dilewati `changed`
                'plan.*.order_id' => ['required', 'integer'],
                'plan.*.sellers' => ['present', 'array'],
                'plan.*.sellers.*.artist_id' => ['required', 'integer'],
                'plan.*.sellers.*.items_hash' => ['required', 'string'],
            ];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $v) {
            $ids = $this->input('order_ids');
            $max = (int) config('approvals.bulk_max');
            if (is_array($ids) && count($ids) > $max) {
                $v->errors()->add('order_ids', __('approvals.bulk_limit_exceeded', ['max' => $max]));
            }
        }];
    }

    /** @return array<int, int> id penjualan unik, urutan permintaan dipertahankan. */
    public function orderIds(): array
    {
        return array_map('intval', $this->validated('order_ids'));
    }
}
