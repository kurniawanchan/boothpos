<?php

namespace App\Http\Requests\CustomerOrder;

/**
 * Customer commerce (CONTRACT §9) — transisi tanpa data selain catatan
 * opsional: POST /customer-orders/{id}/pack | ready | pickup.
 */
class MoveCustomerOrderRequest extends CustomerOrderFulfilmentRequest
{
    public function rules(): array
    {
        return [
            'note' => $this->noteRules(),
        ];
    }
}
