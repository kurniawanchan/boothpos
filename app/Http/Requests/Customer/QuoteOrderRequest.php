<?php

namespace App\Http\Requests\Customer;

use App\Models\CustomerAccount;
use App\Models\CustomerOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Customer commerce (CONTRACT §8) — badan POST /customer/orders/quote; juga
 * dasar PlaceOrderRequest (badan yang sama + `expected_total`).
 *
 * Di sini hanya BENTUK yang diperiksa. Yang sengaja TIDAK ada:
 *  - rule `exists:` untuk `address_id` / `variant_id`: rule itu menembus
 *    batas akun dan batas DEMO/LIVE; kepemilikan alamat dan kelayakan varian
 *    diputuskan CustomerCheckoutService lewat relasi/scope.
 *  - batas atas `qty`: jumlah di atas batas per baris adalah masalah
 *    keranjang (409 `cart_invalid`, alasan `qty_limit` + `max_qty`), bukan
 *    galat bentuk, supaya aplikasi bisa menawarkan jumlah yang benar.
 *  - field harga/total/status/pemilik: tidak punya rule, jadi tidak pernah
 *    keluar dari validated() — kiriman klien untuk field itu diabaikan.
 */
class QuoteOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('customer') instanceof CustomerAccount;
    }

    /**
     * `distinct` membandingkan setiap pasang baris (kuadratik terhadap jumlah baris) dan
     * validator menjalankannya SEBELUM menilai `max` pada daftarnya. Daftar yang jauh melebihi
     * batas dipotong menjadi `batas + 1` baris: aturan `max` tetap gagal (422 yang sama), tetapi
     * kerja validasi dan jumlah galat per baris tetap terbatas. Baris di luar potongan tidak
     * pernah dipakai karena permintaannya sudah pasti ditolak.
     */
    protected function prepareForValidation(): void
    {
        $maxLines = (int) config('customer_commerce.max_lines_per_order');
        $items = $this->input('items');

        if (is_array($items) && count($items) > $maxLines + 1) {
            $this->merge(['items' => array_slice($items, 0, $maxLines + 1, true)]);
        }
    }

    public function rules(): array
    {
        return [
            'fulfilment_method' => ['required', 'string', Rule::in(CustomerOrder::FULFILMENT_METHODS)],
            // Wajib hanya untuk kurir; untuk ambil di tempat nilainya diabaikan service.
            'address_id' => ['required_if:fulfilment_method,'.CustomerOrder::FULFILMENT_COURIER, 'nullable', 'integer', 'min:1'],
            'contact' => ['nullable', 'array'],
            'contact.name' => ['nullable', 'string', 'max:100'],
            'contact.phone' => ['nullable', 'string', 'max:30'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:'.(int) config('customer_commerce.max_lines_per_order')],
            'items.*.variant_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
