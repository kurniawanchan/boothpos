<?php

namespace App\Http\Resources\Customer;

use App\Models\CustomerAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer commerce (CONTRACT §7) — alamat tersimpan sebagaimana dilihat
 * pemiliknya. Sepuluh field kontrak; `customer_account_id` dan cap waktu
 * tidak ikut.
 *
 * @mixin CustomerAddress
 */
class CustomerAddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'recipient_name' => $this->recipient_name,
            'phone' => $this->phone,
            'address_line' => $this->address_line,
            'city' => $this->city,
            'province' => $this->province,
            'postal_code' => $this->postal_code,
            'notes' => $this->notes,
            'is_default' => (bool) $this->is_default,
        ];
    }
}
