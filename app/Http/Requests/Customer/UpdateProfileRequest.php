<?php

namespace App\Http\Requests\Customer;

use App\Models\CustomerAccount;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Customer commerce (CONTRACT §5) — PUT /customer/me.
 *
 * Hanya `name` dan `phone` yang punya rule, jadi hanya keduanya yang keluar
 * dari validated(): e-mail (tak bisa diubah di v1), kata sandi, status
 * verifikasi, `customer_id`, dan kolom lain yang dikirim klien diabaikan,
 * bukan diterapkan.
 */
class UpdateProfileRequest extends FormRequest
{
    /** Akun selalu mengubah profilnya SENDIRI; tidak ada id akun di URL maupun di body. */
    public function authorize(): bool
    {
        return $this->user('customer') instanceof CustomerAccount;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
        ];
    }
}
