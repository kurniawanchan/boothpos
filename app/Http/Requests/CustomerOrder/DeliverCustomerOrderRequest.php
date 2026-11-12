<?php

namespace App\Http\Requests\CustomerOrder;

/**
 * Customer commerce (CONTRACT §9) — POST /customer-orders/{id}/deliver.
 * `delivered_at` tidak boleh di masa depan; bahwa ia tidak boleh lebih awal
 * daripada waktu pengiriman diperiksa service terhadap baris pengiriman yang
 * terkunci (422 `errors.delivered_at`).
 */
class DeliverCustomerOrderRequest extends CustomerOrderFulfilmentRequest
{
    public function rules(): array
    {
        return [
            'delivered_at' => ['nullable', 'date', 'before_or_equal:now'],
            'note' => $this->noteRules(),
        ];
    }
}
