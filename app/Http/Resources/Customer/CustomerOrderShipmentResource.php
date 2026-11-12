<?php

namespace App\Http\Resources\Customer;

use App\Models\CustomerOrderShipment;
use App\Support\CustomerOrderTrackingUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer commerce (CONTRACT §8) — pengiriman kurir sebagaimana dicatat
 * toko (bukan telemetri kurir).
 *
 * `tracking_url` dibentuk server dari templat yang diizinkan
 * (CustomerOrderTrackingUrl) dan bawaannya null; tidak ada URL yang disimpan
 * atau diterima dari staf/klien.
 *
 * @mixin CustomerOrderShipment
 */
class CustomerOrderShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'courier_name' => $this->courier_name,
            'service_name' => $this->service_name,
            'tracking_number' => $this->tracking_number,
            'tracking_url' => CustomerOrderTrackingUrl::for($this->courier_name, $this->tracking_number),
            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'shipping_cost' => CustomerOrderSummaryResource::money($this->shipping_cost),
        ];
    }
}
