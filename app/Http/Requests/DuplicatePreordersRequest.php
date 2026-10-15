<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 027-preorder-duplicate-split — bentuk saja yang dicek di sini. Aturan
 * bisnis (barang masih bisa dijual, diskon tak melebihi total, id milik mode
 * data yang aktif) dinilai PER pre-order di PreorderService::duplicate() dan
 * dilaporkan per baris, supaya satu yang gagal tidak menggagalkan yang lain.
 *
 * `exists:` tidak melihat global scope DEMO/LIVE, jadi id mode lain lolos
 * validasi ini dan baru terdeteksi sebagai "tidak ditemukan" di controller.
 */
class DuplicatePreordersRequest extends FormRequest
{
    // Sama seperti StorePreorderRequest: semua peran terautentikasi boleh membuat pre-order.
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            'preorder_ids' => ['required', 'array', 'min:1', 'max:100'],
            'preorder_ids.*' => ['integer', 'exists:preorders,id'],
        ];
    }
}
