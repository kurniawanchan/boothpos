<?php

namespace App\Http\Requests\CustomerOrder;

use App\Rules\CustomerVisiblePlainText;
use Illuminate\Validation\Validator;

/**
 * Customer commerce (CONTRACT §9) — PATCH /customer-orders/{id}/shipment:
 * memperbarui nomor resi dan/atau nama layanan selama pesanan `shipped`.
 *
 * Minimal satu dari dua kunci harus DIKIRIM. `null` eksplisit mengosongkan
 * nilainya; kunci yang tidak dikirim tidak disentuh — karena itu controller
 * hanya meneruskan kunci yang benar-benar ada di badan permintaan.
 * Kurir dan ongkir tidak bisa diubah lewat sini.
 */
class UpdateCustomerOrderShipmentRequest extends CustomerOrderFulfilmentRequest
{
    public const FIELDS = ['tracking_number', 'service_name'];

    public function rules(): array
    {
        return [
            'tracking_number' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:'.ShipCustomerOrderRequest::TRACKING_NUMBER_PATTERN],
            'service_name' => ['sometimes', 'nullable', 'string', 'max:50', new CustomerVisiblePlainText],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->exists('tracking_number') && ! $this->exists('service_name')) {
                $validator->errors()->add('tracking_number', __('customer_commerce.fulfilment.shipment_nothing_to_update'));
            }
        });
    }

    /**
     * Hanya kunci yang dikirim klien (validated() bisa menghilangkan kunci
     * bernilai null pada sebagian aturan; `exists()` membaca badan aslinya).
     *
     * @return array<string, string|null>
     */
    public function changes(): array
    {
        $changes = [];

        foreach (self::FIELDS as $field) {
            if ($this->exists($field)) {
                $changes[$field] = $this->validated($field);
            }
        }

        return $changes;
    }
}
