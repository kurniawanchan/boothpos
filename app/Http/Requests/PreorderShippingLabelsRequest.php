<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 069-courier-shipping-label-a4 — bentuk permintaan label kurir (tunggal maupun massal).
 *
 * Hak dijaga middleware `ability:preorders,export` di rute. SENGAJA tanpa aturan
 * `exists:preorders,id`: aturan itu menembus DataModeScope/SellerScope sehingga
 * bisa membedakan "pre-order mode/seller lain" dari "tidak ada". Id yang tak
 * terlihat dilaporkan sebagai `skipped: not_found` oleh PreorderShippingLabels
 * (research D2), sama seperti aksi massal 064/065.
 */
class PreorderShippingLabelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'preorder_ids' => ['required', 'array', 'min:1', 'max:'.$this->maxIds()],
            'preorder_ids.*' => ['integer', 'distinct'],
        ];
    }

    public function messages(): array
    {
        $max = $this->maxIds();

        return [
            'preorder_ids.max' => __('Maksimal :max pre-order per unduhan label kurir.', ['max' => $max]),
        ];
    }

    private function maxIds(): int
    {
        return (int) config('preorders.bulk_max', 200);
    }
}
