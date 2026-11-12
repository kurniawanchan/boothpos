<?php

namespace App\Http\Resources\Customer;

use App\Models\CustomerOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer commerce (CONTRACT §8) — pelacakan pesanan: status, pengiriman,
 * dan linimasa (terbaru dulu).
 *
 * `updated_by: "store"` adalah sinyal bagi aplikasi untuk menampilkan
 * "Diperbarui oleh toko": seluruh isi di sini dicatat staf, bukan data
 * langsung dari kurir. Nilainya baru berubah bila integrasi kurir kelak ada.
 *
 * @mixin CustomerOrder
 */
class CustomerOrderTrackingResource extends JsonResource
{
    public const UPDATED_BY_STORE = 'store';

    public function toArray(Request $request): array
    {
        return [
            'order_number' => $this->order_number,
            'status' => $this->status,
            'fulfilment_method' => $this->fulfilment_method,
            'updated_by' => self::UPDATED_BY_STORE,
            'shipment' => $this->shipment !== null ? new CustomerOrderShipmentResource($this->shipment) : null,
            'timeline' => CustomerOrderEventResource::collection($this->statusEvents),
        ];
    }
}
