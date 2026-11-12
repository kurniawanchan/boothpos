<?php

namespace App\Http\Resources;

use App\Models\CustomerOrder;
use App\Support\ReportSplit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer commerce (CONTRACT §9) — baris daftar pesanan pelanggan untuk
 * STAF (juga kepala detailnya).
 *
 * Sengaja BUKAN turunan resource pelanggan (App\Http\Resources\Customer\*):
 * staf melihat id numerik (parameter rute staf) dan kontak pemesan, yang
 * tidak pernah boleh ikut ke bentuk publik — dan sebaliknya perubahan bentuk
 * publik tidak boleh mengubah layar staf.
 *
 * `item_count` / `first_item_name` dibaca dari kolom hasil subquery (daftar)
 * atau dari relasi `items` yang sudah dimuat (detail); tidak pernah lazy-load
 * per pesanan.
 *
 * @mixin CustomerOrder
 */
class StaffCustomerOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->getAttributes();
        $itemsLoaded = $this->resource->relationLoaded('items');

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'fulfilment_method' => $this->fulfilment_method,
            'contact_name' => $this->contact_name,
            'contact_phone' => $this->contact_phone,
            'total' => self::money($this->total),
            'shipping_cost_pending' => $this->shippingCostPending(),
            'item_count' => array_key_exists('items_count', $attributes)
                ? (int) $attributes['items_count']
                : ($itemsLoaded ? $this->items->count() : 0),
            'first_item_name' => array_key_exists('first_item_name', $attributes)
                ? $attributes['first_item_name']
                : ($itemsLoaded ? $this->items->first()?->name_snapshot : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** Uang di kabel selalu string dua desimal. */
    public static function money(mixed $value): string
    {
        return ReportSplit::money(ReportSplit::cents($value));
    }
}
