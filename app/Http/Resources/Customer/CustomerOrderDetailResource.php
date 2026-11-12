<?php

namespace App\Http\Resources\Customer;

use App\Models\CustomerAddress;
use App\Models\CustomerOrder;
use App\Support\CustomerCommerceGate;
use Illuminate\Http\Request;

/**
 * Customer commerce (CONTRACT §8) — detail pesanan untuk PEMILIKNYA:
 * ringkasan + uang, kontak dan alamat (snapshot saat checkout), item
 * (snapshot nama/SKU/harga), pengiriman, dan linimasa.
 *
 * Semua yang tampil adalah snapshot pesanan, tidak dibaca ulang dari profil,
 * alamat tersimpan, atau varian. `instructions` adalah teks petunjuk toko
 * untuk metode pesanan ini (dirender server, teks polos).
 *
 * Pemanggil WAJIB memuat `items`, `shipment`, dan `statusEvents`
 * (CustomerOrderQueryService::findFor / CustomerCheckoutService::place).
 *
 * @mixin CustomerOrder
 */
class CustomerOrderDetailResource extends CustomerOrderSummaryResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'subtotal' => self::money($this->subtotal),
            'shipping_cost' => self::money($this->shipping_cost),
            'shipping_cost_pending' => $this->shippingCostPending(),
            'contact' => [
                'name' => $this->contact_name,
                'phone' => $this->contact_phone,
                'email' => $this->contact_email,
            ],
            'shipping_address' => $this->addressSnapshot(),
            'notes' => $this->notes,
            'cancel_reason' => $this->cancel_reason,
            'instructions' => CustomerCommerceGate::instructionsFor($this->fulfilment_method),
            'items' => $this->items->map(fn ($item) => [
                'name' => $item->name_snapshot,
                'sku' => $item->sku_snapshot,
                'unit_price' => self::money($item->unit_price),
                'qty' => (int) $item->qty,
                'line_total' => self::money($item->line_total),
            ])->values()->all(),
            'shipment' => $this->shipment !== null ? new CustomerOrderShipmentResource($this->shipment) : null,
            'timeline' => CustomerOrderEventResource::collection($this->statusEvents),
        ];
    }

    /**
     * Snapshot alamat dengan tujuh kunci kontrak dalam urutan tetap (kolom
     * JSON MySQL tidak menjaga urutan kunci); kunci lain yang mungkin
     * tersimpan tidak ikut.
     *
     * @return array<string, string|null>|null
     */
    private function addressSnapshot(): ?array
    {
        $snapshot = $this->shipping_address;

        if (! is_array($snapshot)) {
            return null;
        }

        $address = [];
        foreach (CustomerAddress::SNAPSHOT_FIELDS as $field) {
            $address[$field] = $snapshot[$field] ?? null;
        }

        return $address;
    }
}
