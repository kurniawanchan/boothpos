<?php

namespace App\Http\Resources;

use App\Models\CustomerAddress;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\CustomerOrderStatusEvent;
use App\Services\CustomerCommerce\CustomerOrderTransitionService;
use App\Support\CustomerOrderTrackingUrl;
use Illuminate\Http\Request;

/**
 * Customer commerce (CONTRACT §9) — detail pesanan pelanggan untuk STAF:
 * semua yang dilihat pelanggan DITAMBAH detail audit yang tidak pernah
 * dikirim ke pelanggan — `metadata` peristiwa, jenis dan nama pelaku, id
 * item/varian, stok tersedia, dan cap waktu internal.
 *
 * Pemanggil WAJIB memuat `items.variant`, `shipment`, dan
 * `statusEvents.actor` (lihat StaffCustomerOrderQuery::loadDetail). Relasi
 * yang tidak dimuat TIDAK dijadikan "kosong": resource ini membacanya
 * langsung, jadi lupa memuat berarti lazy-load, bukan field yang hilang.
 *
 * `available_actions` / `can_update_shipment` dihitung server dari tabel
 * transisi DAN izin aksi pengguna, supaya layar staf tidak menurunkan izin
 * dari nama peran. Itu hanya petunjuk tombol; penegakan tetap di middleware
 * `ability` dan di CustomerOrderTransitionService.
 *
 * @mixin CustomerOrder
 */
class StaffCustomerOrderDetailResource extends StaffCustomerOrderResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return parent::toArray($request) + [
            'customer_id' => $this->customer_id,
            'contact' => [
                'name' => $this->contact_name,
                'phone' => $this->contact_phone,
                'email' => $this->contact_email,
            ],
            'shipping_address' => $this->addressSnapshot(),
            'notes' => $this->notes,
            'cancel_reason' => $this->cancel_reason,
            'subtotal' => self::money($this->subtotal),
            'shipping_cost' => self::money($this->shipping_cost),
            'stock_deducted_at' => $this->stock_deducted_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'items' => $this->items->map(fn (CustomerOrderItem $item) => [
                'id' => $item->id,
                'variant_id' => $item->variant_id,
                'sku' => $item->sku_snapshot,
                'name' => $item->name_snapshot,
                'unit_price' => self::money($item->unit_price),
                'qty' => (int) $item->qty,
                'line_total' => self::money($item->line_total),
                // null = varian sudah tidak terjangkau (dihapus / mode data lain): konfirmasi akan ditolak.
                'available_stock' => $item->variant !== null ? (int) $item->variant->current_stock : null,
            ])->values()->all(),
            'shipment' => $this->shipment === null ? null : [
                'courier_name' => $this->shipment->courier_name,
                'service_name' => $this->shipment->service_name,
                'tracking_number' => $this->shipment->tracking_number,
                'tracking_url' => CustomerOrderTrackingUrl::for($this->shipment->courier_name, $this->shipment->tracking_number),
                'dispatched_at' => $this->shipment->dispatched_at?->toIso8601String(),
                'delivered_at' => $this->shipment->delivered_at?->toIso8601String(),
                'shipping_cost' => self::money($this->shipment->shipping_cost),
            ],
            'timeline' => $this->statusEvents->map(fn (CustomerOrderStatusEvent $event) => [
                'id' => $event->id,
                'status' => $event->status,
                'event_type' => $event->event_type,
                'note' => $event->note,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'actor_type' => $event->actor_type,
                'actor_user_id' => $event->actor_user_id,
                'actor_name' => $event->actor?->name,
                'metadata' => $event->metadata,
            ])->values()->all(),
            'available_actions' => array_values(array_filter(
                CustomerOrderTransitionService::availableActions($this->resource),
                fn (string $action) => $user?->canDo('customer_orders', $action) ?? false,
            )),
            'can_update_shipment' => CustomerOrderTransitionService::shipmentEditable($this->resource)
                && ($user?->canDo('customer_orders', CustomerOrderTransitionService::ACTION_SHIP) ?? false),
        ];
    }

    /**
     * Snapshot alamat dengan tujuh kunci kontrak dalam urutan tetap (kolom
     * JSON MySQL tidak menjaga urutan kunci).
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
