<?php

namespace App\Http\Resources\Customer;

use App\Models\CustomerOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer commerce (CONTRACT §8) — ringkasan pesanan untuk daftar
 * "Pesanan saya" (juga bagian awal detail).
 *
 * Pengenal publiknya `order_number`; id numerik (dipakai rute staf), id akun,
 * mode data, dan kunci idempotensi tidak pernah ikut.
 *
 * `item_count` = jumlah BARIS pesanan (bukan jumlah unit), berpasangan
 * dengan `first_item_name` untuk teks "X dan N barang lainnya". Keduanya
 * dibaca dari kolom hasil subquery bila ada (daftar), selain itu dari relasi
 * `items` yang sudah dimuat (detail) — tidak pernah lazy-load per pesanan.
 *
 * @mixin CustomerOrder
 */
class CustomerOrderSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->getAttributes();
        $itemsLoaded = $this->resource->relationLoaded('items');

        return [
            'order_number' => $this->order_number,
            'status' => $this->status,
            'fulfilment_method' => $this->fulfilment_method,
            'total' => self::money($this->total),
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
        return number_format((float) $value, 2, '.', '');
    }
}
