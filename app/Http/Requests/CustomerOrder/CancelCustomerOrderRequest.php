<?php

namespace App\Http\Requests\CustomerOrder;

use App\Rules\CustomerVisiblePlainText;

/**
 * Customer commerce (CONTRACT §9) — POST /customer-orders/{id}/cancel.
 * Alasan WAJIB dan dilihat pelanggan (alasan pembatalan + catatan linimasa),
 * jadi harus teks biasa.
 */
class CancelCustomerOrderRequest extends CustomerOrderFulfilmentRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255', new CustomerVisiblePlainText],
        ];
    }
}
