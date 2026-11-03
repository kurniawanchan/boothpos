<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 090 — kiriman pesan mandiri dari halaman PUBLIK. Tidak ada pengguna, jadi otorisasi adalah token QR (middleware
 * `self-service.table`), bukan peran. Request ini hanya membentuk dan membatasi input; uang tidak pernah diterima:
 * semua field harga/diskon/total/outlet/meja DITOLAK (`prohibited`), bukan diabaikan diam-diam (Konstitusi IV).
 */
class SubmitSelfServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'local_ref' => ['required', 'uuid'],
            'service_type' => ['required', 'in:dine_in,takeaway'],
            'proof_token' => ['required', 'uuid'],

            // US4 — data diri opsional; tamu = tanpa blok `customer` (atau semua kolomnya kosong).
            'customer' => ['sometimes', 'nullable', 'array'],
            'customer.name' => ['nullable', 'string', 'max:100'],
            'customer.phone' => ['nullable', 'string', 'max:30'],
            'customer.email' => ['nullable', 'email', 'max:150'],

            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.note' => ['nullable', 'string', 'max:200'],
            // Pilihan berjumlah (089) dan bentuk id-saja; aturan min/maks per pilihan diperiksa ModifierResolver.
            'items.*.modifier_selections' => ['sometimes', 'array', 'max:50'],
            'items.*.modifier_selections.*.option_id' => ['required', 'integer'],
            'items.*.modifier_selections.*.qty' => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.modifier_option_ids' => ['sometimes', 'array', 'max:50'],
            'items.*.modifier_option_ids.*' => ['integer'],

            // Dilarang keras: tanpa diskon, tanpa harga/total dari klien, outlet dan meja hanya dari token QR.
            'discount_amount' => ['prohibited'],
            'discount_percent' => ['prohibited'],
            'discount' => ['prohibited'],
            'outlet_id' => ['prohibited'],
            'table_id' => ['prohibited'],
            'subtotal' => ['prohibited'],
            'tax_amount' => ['prohibited'],
            'tax_rate' => ['prohibited'],
            'total_amount' => ['prohibited'],
            'items.*.unit_price' => ['prohibited'],
            'items.*.sell_price' => ['prohibited'],
            'items.*.price' => ['prohibited'],
            'items.*.line_total' => ['prohibited'],
            'items.*.discount_amount' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'proof_token.required' => __('self_service.proof_required'),
            'items.required' => __('self_service.cart_empty'),
            'items.min' => __('self_service.cart_empty'),
        ];
    }

    /** Mengisi data diri berarti nama DAN telepon wajib, dan telepon harus nomor yang masuk akal. */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($v) {
            $c = (array) $this->input('customer', []);
            $filled = collect(['name', 'phone', 'email'])->contains(fn ($k) => trim((string) ($c[$k] ?? '')) !== '');
            if (! $filled) {
                return;
            }
            if (trim((string) ($c['name'] ?? '')) === '') {
                $v->errors()->add('customer.name', __('self_service.customer_name_required'));
            }
            if (\App\Support\PhoneNormalizer::canonical($c['phone'] ?? null) === null) {
                $v->errors()->add('customer.phone', __('self_service.customer_phone_invalid'));
            }
        });
    }
}
