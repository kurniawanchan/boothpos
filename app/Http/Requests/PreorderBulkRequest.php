<?php

namespace App\Http\Requests;

use App\Models\Preorder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 065 — bentuk permintaan aksi massal pre-order (pratinjau DAN terapkan) untuk kedua aksi (status, penanda progres). Semua isi JSON (tanpa
 * berkas, jadi `max_input_vars` PHP tak berlaku). Hak dan kelayakan per pre-order BUKAN di sini — hak dijaga
 * middleware `ability:` per rute, kelayakan dinilai service yang sama dengan aksi individual (yang tak layak
 * DILEWATI, bukan membuat permintaan gagal). Di sini hanya bentuk, batas jumlah, dan isian wajib bersyarat.
 */
class PreorderBulkRequest extends FormRequest
{
    public const ACTIONS = ['status', 'dispatch'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function action(): string
    {
        return (string) $this->route('action');
    }

    private function isApply(): bool
    {
        return ! str_ends_with($this->path(), '/preview');
    }

    public function rules(): array
    {
        $rules = [
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', 'distinct'],
        ];

        $apply = $this->isApply();

        switch ($this->action()) {
            case 'status':
                // Pratinjau tanpa `params.status` sah: dipakai UI untuk mengetahui pilihan status yang tersedia.
                $rules += [
                    'params' => [$apply ? 'required' : 'sometimes', 'array'],
                    'params.status' => [$apply ? 'required' : 'sometimes', 'in:dp_paid,arrived,settled,handed_over,cancelled'],
                    'params.cancel_reason' => ['nullable', 'string', 'max:255'],
                ];
                break;

            case 'dispatch':
                $rules += [
                    'params' => ['required', 'array'],
                    'params.dispatch_status' => ['required', 'in:'.implode(',', Preorder::DISPATCH_STATUSES)],
                ];
                break;
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $v) {
            $ids = $this->input('order_ids');
            $max = (int) config('preorders.bulk_max');

            if (is_array($ids) && count($ids) > $max) {
                $v->errors()->add('order_ids', __('preorders.bulk_limit_exceeded', ['max' => $max]));
            }

            // Pembatalan tanpa alasan ditolak (422, tak ada yang ditulis).
            if ($this->action() === 'status' && $this->input('params.status') === 'cancelled'
                && trim((string) $this->input('params.cancel_reason')) === '') {
                $v->errors()->add('params.cancel_reason', __('preorders.bulk_cancel_reason_required'));
            }
        }];
    }

    /** @return array<int, int> id pre-order unik, urutan permintaan dipertahankan. */
    public function orderIds(): array
    {
        return array_map('intval', $this->validated('order_ids'));
    }
}
