<?php

namespace App\Http\Requests\Customer;

use App\Models\CustomerAccount;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Customer commerce (CONTRACT §7) — POST /customer/addresses dan
 * PUT /customer/addresses/{id} (aturan yang sama untuk keduanya).
 *
 * Hanya field di bawah yang keluar dari validated(): `customer_account_id`,
 * `id`, dan kolom lain yang dikirim klien diabaikan, bukan diterapkan —
 * pemilik alamat selalu akun pemegang token. Batas panjang mengikuti kolom
 * tabel `customer_addresses`.
 *
 * Tidak ada rule `exists:` di sini: kepemilikan alamat diperiksa
 * CustomerAddressService lewat `$account->addresses()`.
 */
class AddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('customer') instanceof CustomerAccount;
    }

    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:50'],
            'recipient_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30'],
            'address_line' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'province' => ['required', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'notes' => ['nullable', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
