<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ActivateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('activate', $this->route('company')) ?? false;
    }

    // 019-billing-system (third expansion) — tidak ada lagi kode yang
    // dikirim ulang oleh klien untuk divalidasi; syarat aktivasi sekarang
    // dicek dari data yang sudah ada di sistem (Invoice 'paid'), bukan
    // dari input request, jadi tidak ada aturan validasi field apa pun.
    public function rules(): array
    {
        return [];
    }
}
